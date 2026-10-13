<?php

namespace App\Events\Ticket;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a status change is committed to the database (ShouldDispatchAfterCommit: held
 * until the surrounding transaction commits, dropped if it rolls back). Listeners, all
 * queued so a failure never turns an already-saved change into an error for the user:
 *
 *  - NotifyTicketResolved: tells the customer when the ticket moves to Resolved.
 */
class TicketStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $actor,
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
    ) {}
}
