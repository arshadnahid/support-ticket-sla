<?php

namespace App\Events\Ticket;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a ticket is committed to the database (ShouldDispatchAfterCommit: held
 * until the surrounding transaction commits, dropped if it rolls back). Listeners, all
 * queued so a failure never turns an already-saved ticket into an error for the user:
 *
 *  - NotifyTicketCreated: confirmation to the customer + notification to all active admins
 *    (TicketCreatedNotification, itself queued; stored in the `notifications` table).
 *  - QueueAutoAssignment: when tickets.assignment.auto_assign is on, dispatches the
 *    AutoAssignTicket job; the agent is then told by NotifyTicketAssigned.
 */
class TicketCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $actor,
    ) {}
}
