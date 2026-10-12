<?php

namespace App\Events\Ticket;

use App\Enums\SlaState;
use App\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket was escalated because it is approaching its SLA deadline or has passed it.
 * Fired once per ticket per level, after the escalation is saved.
 */
class TicketSlaEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly SlaState $state,
    ) {}
}
