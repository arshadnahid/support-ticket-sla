<?php

namespace App\Services\Ticket;

use App\DTOs\Ticket\CreateTicketDTO;
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
    ) {}

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function list(User $user, TicketListScope $scope, int $perPage): LengthAwarePaginator
    {
        return $this->tickets->paginateFor($user, $scope, $perPage);
    }

    public function show(Ticket $ticket): Ticket
    {
        return $this->tickets->loadRelations($ticket);
    }

    public function create(CreateTicketDTO $dto, User $creator): Ticket
    {
        // Number and ticket are saved in one transaction: if the insert fails the
        // counter rolls back too, so ticket numbers have no gaps.
        $ticket = DB::transaction(function () use ($dto, $creator): Ticket {
            $ticket = $this->tickets->create([
                'number' => $this->numbers->next(),
                'subject' => $dto->subject,
                'description' => $dto->description,
                'priority' => $dto->priority,
                'status' => TicketStatus::Open,
                'category_id' => $dto->categoryId,
                'created_by' => $creator->id,
            ]);

            TicketCreated::dispatch($ticket, $creator);

            return $ticket;
        }, attempts: 3); // retried if MySQL picks this transaction as a deadlock victim

        return $this->tickets->loadRelations($ticket);
    }

    public function update(Ticket $ticket, UpdateTicketDTO $dto, User $actor): Ticket
    {
        $locked = DB::transaction(function () use ($ticket, $dto, $actor): Ticket {
            // Lock the row so two simultaneous edits cannot overwrite each other silently.
            $locked = $this->tickets->lockForUpdate($ticket->id);

            $changes = $this->diff($locked, $dto->changes());

            if ($changes !== []) {
                $this->tickets->update($locked, array_map(fn (array $change) => $change['to'], $changes));

                TicketUpdated::dispatch($locked, $actor, $changes);
            }

            return $locked;
        });

        return $this->tickets->loadRelations($locked);
    }

    /**
     * @throws InvalidTicketTransitionException
     */
    public function changeStatus(Ticket $ticket, TicketStatus $to, User $actor): Ticket
    {
        $locked = DB::transaction(function () use ($ticket, $to, $actor): Ticket {
            // The status is checked against the locked, up-to-date row. Without the
            // lock, two agents could both "resolve" the same ticket from a stale copy.
            $locked = $this->tickets->lockForUpdate($ticket->id);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidTicketTransitionException($from, $to);
            }

            $this->tickets->update($locked, ['status' => $to] + $this->timestampsFor($from, $to));

            TicketStatusChanged::dispatch($locked, $actor, $from, $to);

            return $locked;
        });

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
