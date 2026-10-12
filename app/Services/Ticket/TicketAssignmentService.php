<?php

namespace App\Services\Ticket;

use App\Enums\AssignmentMethod;
use App\Events\Ticket\TicketAssigned;
use App\Exceptions\TicketAssignmentException;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Agent\AgentInterfaces\AgentInterface;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use App\Services\Ticket\Assignment\AssignmentStrategy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Who works on a ticket. Three ways in, one rule for all of them:
 *
 *   assign()      an admin gives the ticket to an agent (may also reassign)
 *   accept()      an agent takes an unassigned ticket for themselves
 *   autoAssign()  the queued job picks an agent (see LeastWorkloadStrategy)
 *
 * Concurrency (assessment section 5): every method re-reads the ticket with
 * SELECT ... FOR UPDATE inside a transaction and decides from THAT row, never from the
 * copy loaded earlier in the request. If two agents accept at the same moment, the
 * database lets one through the lock first; when the second gets the lock it sees the
 * ticket already has an assignee and is refused. Exactly one winner, no lost update.
 */
class TicketAssignmentService
{
    public function __construct(
        private readonly TicketInterface $tickets,
        private readonly AgentInterface $agents,
        private readonly AssignmentStrategy $strategy,
    ) {}

    /**
     * Admin: give the ticket to $agentId, replacing any current assignee.
     *
     * @throws TicketAssignmentException
     */
    public function assign(Ticket $ticket, int $agentId, User $actor): Ticket
    {
        $agent = $this->agents->findActiveAgent($agentId) ?? throw TicketAssignmentException::notAnAgent();

        $locked = DB::transaction(function () use ($ticket, $agent, $actor): Ticket {
            $locked = $this->tickets->lockForUpdate($ticket->id);

            $this->guardAssignable($locked);

            // Already theirs: nothing to change, no event.
            if ($locked->assigned_to !== $agent->id) {
                $this->applyAssignment($locked, $agent, AssignmentMethod::Manual, $actor);
            }

            return $locked;
        }, attempts: 3);

        return $this->tickets->loadRelations($locked);
    }

    /**
     * Agent: take an unassigned ticket.
     *
     * @throws TicketAssignmentException when someone else already has it, or it is finished
     */
    public function accept(Ticket $ticket, User $agent): Ticket
    {
        $locked = DB::transaction(function () use ($ticket, $agent): Ticket {
            // The lock is the whole point: the second agent waits here until the first commits.
            $locked = $this->tickets->lockForUpdate($ticket->id);

            $this->guardAssignable($locked);

            // Pressing "accept" twice is harmless: it is still theirs.
            if ($locked->assigned_to === $agent->id) {
                return $locked;
            }

            if ($locked->assigned_to !== null) {
                throw TicketAssignmentException::alreadyAssigned();
            }

            $this->applyAssignment($locked, $agent, AssignmentMethod::Accepted, $agent);

            return $locked;
        }, attempts: 3);

        return $this->tickets->loadRelations($locked);
    }

    /**
     * Queue job: pick an agent for the ticket. Returns the ticket when it was assigned, or
     * null when there was nothing to do (already taken, finished, deleted, or no agent free).
     * "Nothing to do" is normal, not an error: the sweep command will try again later.
     */
    public function autoAssign(int $ticketId): ?Ticket
    {
        return DB::transaction(function () use ($ticketId): ?Ticket {
            try {
                $locked = $this->tickets->lockForUpdate($ticketId);
            } catch (ModelNotFoundException) {
                return null;
            }

            // Re-checked under the lock: an agent may have accepted it a moment ago.
            if ($locked->assigned_to !== null || ! $locked->status->isActive()) {
                return null;
            }

            // Lock order is always ticket first, then agents by id, so concurrent assignments
            // queue up instead of deadlocking. Holding the agent locks also means the workload
            // numbers read next cannot be changed by another automatic assignment meanwhile.
            $agent = $this->strategy->choose($locked, $this->agents->availableWithWorkload(lock: true));

            if ($agent === null) {
                return null;
            }

            $this->applyAssignment($locked, $agent, AssignmentMethod::Auto, actor: null);

            return $locked;
        }, attempts: 3);
    }

    /**
     * @throws TicketAssignmentException
     */
    private function guardAssignable(Ticket $locked): void
    {
        if (! $locked->status->isActive()) {
            throw TicketAssignmentException::notAssignable($locked->status);
        }
    }

    private function applyAssignment(Ticket $locked, User $agent, AssignmentMethod $method, ?User $actor): void
    {
        $previous = $locked->assigned_to;

        $this->tickets->update($locked, ['assigned_to' => $agent->id, 'assigned_at' => now()]);
        $this->agents->recordAssignment($agent);

        TicketAssigned::dispatch($locked, $agent, $method, $actor, $previous);
    }
}
