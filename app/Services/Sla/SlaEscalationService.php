<?php

namespace App\Services\Sla;

use App\Enums\SlaState;
use App\Events\Ticket\TicketSlaEscalated;
use App\Models\TicketActivity;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use Illuminate\Support\Facades\DB;

/**
 * Escalates one ticket. An escalation is: remember the level on the ticket, write a history
 * entry, and fire TicketSlaEscalated (which notifies the right people).
 *
 * Safe to run any number of times for the same ticket, even at the same moment: the ticket row
 * is locked and its state re-read, and a level is only ever escalated once.
 */
class SlaEscalationService
{
    public function __construct(
        private readonly TicketInterface $tickets,
        private readonly SlaService $sla,
    ) {}

    /**
     * @return SlaState|null the level escalated to, or null when there was nothing to do
     */
    public function escalate(int $ticketId): ?SlaState
    {
        return DB::transaction(function () use ($ticketId): ?SlaState {
            $ticket = $this->tickets->lockForUpdate($ticketId);

            // Finished tickets are not escalated: the work is done.
            if (! $ticket->status->isActive()) {
                return null;
            }

            $state = $this->sla->stateOf($ticket);

            // Nothing to escalate, or this level (or a higher one) was already escalated.
            if ($state === null || $state === SlaState::Within || $state->rank() <= $ticket->escalation_level) {
                return null;
            }

            $this->tickets->update($ticket, [
                'escalation_level' => $state->rank(),
                'escalated_at' => now(),
            ]);

            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => null, // the system
                'type' => $state === SlaState::Breached ? TicketActivity::SLA_BREACHED : TicketActivity::SLA_WARNING,
                'description' => $state === SlaState::Breached
                    ? 'SLA breached: the resolution deadline passed.'
                    : 'SLA warning: the ticket is approaching its resolution deadline.',
                'properties' => [
                    'state' => $state->value,
                    'due_at' => $ticket->due_at?->toIso8601String(),
                    'priority' => $ticket->priority->value,
                    'assigned_to' => $ticket->assigned_to,
                ],
            ]);

            TicketSlaEscalated::dispatch($ticket, $state);

            return $state;
        }, attempts: 3);
    }
}
