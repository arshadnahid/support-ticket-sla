<?php

namespace App\Listeners\Ticket;

use App\Events\Ticket\TicketAssigned;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Assigned (automatically, by an admin, or by an agent accepting it): the agent is told the
 * ticket is theirs (unless they did it themselves), the customer is told who handles it.
 */
class NotifyTicketAssigned
{
    public function handle(TicketAssigned $event): void
    {
        $ticket = $event->ticket->loadMissing('creator');

        if ($event->assignee->is_active && $event->actor?->id !== $event->assignee->id) {
            Notification::send($event->assignee, new TicketAssignedNotification($ticket, $event->assignee, forAssignee: true));
        }

        if ($ticket->creator?->is_active) {
            Notification::send($ticket->creator, new TicketAssignedNotification($ticket, $event->assignee, forAssignee: false));
        }
    }
}
