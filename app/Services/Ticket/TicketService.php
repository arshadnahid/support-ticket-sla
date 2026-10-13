<?php

namespace App\Services\Ticket;

use App\DTOs\Ticket\CreateTicketDTO;
use App\DTOs\Ticket\TicketListFilters;
use App\DTOs\Ticket\UpdateTicketDTO;
use App\Enums\TicketListScope;
use App\Enums\TicketStatus;
use App\Events\Ticket\TicketCreated;
use App\Events\Ticket\TicketStatusChanged;
use App\Events\Ticket\TicketUpdated;
use App\Exceptions\InvalidTicketTransitionException;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use App\Services\Sla\SlaService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ticket business rules. Who may do what is decided by TicketPolicy
 * (checked in the controller); this class decides what is valid.
 */
class TicketService
{
    public function __construct(
        private readonly TicketInterface $tickets,
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaService $sla,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function list(User $user, TicketListScope $scope, int $perPage, TicketListFilters $filters): LengthAwarePaginator
    {
        return $this->tickets->paginateFor($user, $scope, $perPage, $filters);
    }

    public function show(Ticket $ticket): Ticket
    {
        return $this->tickets->loadRelations($ticket);
    }

    public function create(CreateTicketDTO $dto, User $creator): Ticket
    {
/*  1. Exception inside the closure (insert fails, the SLA calculation throws, a constraint is violated):
        - Laravel rolls everything back in the transaction.
          That includes the ticket row and the $this->numbers->next() counter increment,
          which is what the comment above the call says, so ticket numbers keep no gaps.
        - The exception is rethrown to the caller of create().
        - The event is never dispatched, so no notification goes out.
    2. Deadlock (MySQL picks this transaction as the victim):
        - Laravel rolls back and re-runs the closure, up to 3 attempts.
          Each attempt gets a fresh ticket number, because the counter was rolled back.
        - If an attempt succeeds, the flow continues normally and the event fires once.
        - If all 3 attempts deadlock, the last exception is thrown. Again, no event.
    3. Failure at commit time: it's treated the same as a closure failure.
        The data is rolled back, the exception is thrown, and no event is dispatched.
*/


        // The ticket number and the ticket row are saved in one transaction.
        // If anything inside fails, both roll back, so ticket numbers have no gaps
        // and the exception propagates to the caller (no event, no relations load).
        $ticket = DB::transaction(function () use ($dto, $creator): Ticket {
            $ticket = $this->tickets->create([
                'number' => $this->numbers->next(),
                'subject' => $dto->subject,
                'description' => $dto->description,
                'priority' => $dto->priority,
                'status' => TicketStatus::Open,
                'category_id' => $dto->categoryId,
                'created_by' => $creator->id,
            ] + $this->sla->deadlinesFor($dto->priority, now())); // SLA due dates derived from priority

            return $ticket;
            // Retried up to 3 times if MySQL picks this transaction as a deadlock victim;
            // each retry re-runs the closure and takes a fresh ticket number.
        }, attempts: 3);

        // Dispatched only after a successful commit, so listeners never see a
        // rolled-back ticket and a deadlock retry cannot fire the event twice.
        TicketCreated::dispatch($ticket, $creator);

        return $this->tickets->loadRelations($ticket);
    }

    public function update(Ticket $ticket, UpdateTicketDTO $dto, User $actor): Ticket
    {
        // The field update and the SLA recalculation are saved in one transaction.
        // If anything inside fails, both roll back and the exception propagates
        // to the caller (no event, no relations load).
        [$locked, $changes] = DB::transaction(function () use ($ticket, $dto): array {
            // Lock the row so two simultaneous edits cannot overwrite each other silently.
            $locked = $this->tickets->lockForUpdate($ticket->id);

            $changes = $this->diff($locked, $dto->changes());

            if ($changes !== []) {
                $this->tickets->update($locked, array_map(fn (array $change) => $change['to'], $changes));

                // A different priority means a different SLA: count it again from the creation time.
                if (isset($changes['priority']) && $locked->status->isActive()) {
                    $this->tickets->update($locked, $this->sla->recalculation($locked));
                }
            }

            return [$locked, $changes];
            // Retried up to 3 times if MySQL picks this transaction as a deadlock victim;
            // each retry re-runs the closure and locks the row again.
        }, attempts: 3);

        // Dispatched only after a successful commit, so listeners never see a
        // rolled-back change and a deadlock retry cannot fire the event twice.
        if ($changes !== []) {
            TicketUpdated::dispatch($locked, $actor, $changes);
        }

        return $this->tickets->loadRelations($locked);
    }

    /**
     * @throws InvalidTicketTransitionException
     */
    public function changeStatus(Ticket $ticket, TicketStatus $to, User $actor): Ticket
    {
        // The transition check and the status update are saved in one transaction.
        // If anything inside fails (including an invalid transition), everything rolls
        // back and the exception propagates to the caller (no event, no relations load).
        [$locked, $from] = DB::transaction(function () use ($ticket, $to): array {
            // The status is checked against the locked, up-to-date row. Without the
            // lock, two agents could both "resolve" the same ticket from a stale copy.
            $locked = $this->tickets->lockForUpdate($ticket->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidTicketTransitionException($from, $to);
            }

            $this->tickets->update($locked, ['status' => $to] + $this->timestampsFor($from, $to));

            return [$locked, $from];
            // Retried up to 3 times if MySQL picks this transaction as a deadlock victim;
            // each retry re-runs the closure and locks the row again.
        }, attempts: 3);

        // Dispatched only after a successful commit, so listeners never see a
        // rolled-back status and a deadlock retry cannot fire the event twice.
        TicketStatusChanged::dispatch($locked, $actor, $from, $to);

        return $this->tickets->loadRelations($locked);
    }

    /**
     * Only the fields that really differ, as column => [from, to].
     *
     * @param  array<string, string|int>  $requested
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(Ticket $ticket, array $requested): array
    {
        $changes = [];

        foreach ($requested as $column => $new) {
            $current = $ticket->getAttribute($column);
            $current = $current instanceof \BackedEnum ? $current->value : $current;

            if ((string) $current !== (string) $new) {
                $changes[$column] = ['from' => $current, 'to' => $new];
            }
        }

        return $changes;
    }

    /**
     * Resolved/closed timestamps that go with a status change.
     *
     * @return array<string, Carbon|null>
     */
    private function timestampsFor(TicketStatus $from, TicketStatus $to): array
    {
        return match ($to) {
            TicketStatus::Resolved => ['resolved_at' => now(), 'closed_at' => null],
            TicketStatus::Closed => ['closed_at' => now()],
            // Reopening a resolved ticket clears its resolved time.
            default => $from === TicketStatus::Resolved ? ['resolved_at' => null] : [],
        };
    }
}
