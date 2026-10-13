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
        [$locked, $agent, $assignment] = DB::transaction(function () use ($ticket, $agentId): array {
            $locked = $this->tickets->lockForUpdate($ticket->id);

            // Checked and locked after the ticket (same order as autoAssign), so the agent
            // cannot be disabled between this check and the commit.
            $agent = $this->agents->findActiveAgent($agentId, lock: true)
                ?? throw TicketAssignmentException::notAnAgent();

            $this->guardAssignable($locked);

            // Already theirs: nothing to change, no event.
            $assignment = $locked->assigned_to !== $agent->id ? $this->applyAssignment($locked, $agent) : null;

            return [$locked, $agent, $assignment];
        }, attempts: 3);

        if ($assignment !== null) {
            TicketAssigned::dispatch($locked, $agent, AssignmentMethod::Manual, $actor, $assignment['previous']);
        }

        return $this->tickets->loadRelations($locked);
    }

    /**
     * Agent: take an unassigned ticket.
     *
     * @throws TicketAssignmentException when someone else already has it, or it is finished
     */
    public function accept(Ticket $ticket, User $agent): Ticket
    {
        [$locked, $assignment] = DB::transaction(function () use ($ticket, $agent): array {
            // The lock is the whole point: the second agent waits here until the first commits.
            $locked = $this->tickets->lockForUpdate($ticket->id);

            $this->guardAssignable($locked);

            // Pressing "accept" twice is harmless: it is still theirs.
            if ($locked->assigned_to === $agent->id) {
                return [$locked, null];
            }

            if ($locked->assigned_to !== null) {
                throw TicketAssignmentException::alreadyAssigned();
            }

            return [$locked, $this->applyAssignment($locked, $agent)];
        }, attempts: 3);

        if ($assignment !== null) {
            TicketAssigned::dispatch($locked, $agent, AssignmentMethod::Accepted, $agent, $assignment['previous']);
        }

        return $this->tickets->loadRelations($locked);
    }

    /**
     * Queue job: pick an agent for the ticket. Returns the ticket when it was assigned, or
     * null when there was nothing to do (already taken, finished, deleted, or no agent free).
     * "Nothing to do" is normal, not an error: the sweep command will try again later.
     */
    public function autoAssign(int $ticketId): ?Ticket
    {
        $result = DB::transaction(function () use ($ticketId): ?array {
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

            return [$locked, $agent, $this->applyAssignment($locked, $agent)];
        }, attempts: 3);

        if ($result === null) {
            return null;
        }

        [$locked, $agent, $assignment] = $result;

        TicketAssigned::dispatch($locked, $agent, AssignmentMethod::Auto, null, $assignment['previous']);

        return $locked;
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

    /**
     * Saves the assignment. The caller dispatches TicketAssigned after the transaction commits.
     *
     * @return array{previous: int|null}
     */
    private function applyAssignment(Ticket $locked, User $agent): array
    {
        $previous = $locked->assigned_to;

        $this->tickets->update($locked, ['assigned_to' => $agent->id, 'assigned_at' => now()]);
        $this->agents->recordAssignment($agent);

        return ['previous' => $previous];
    }
}
