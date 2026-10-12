<?php

namespace App\Listeners\Ticket;

use App\Enums\TicketStatus;
use App\Events\Ticket\TicketStatusChanged;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * A ticket moved to Resolved: tell the customer. Other status changes are not announced.
 */
class NotifyTicketResolved
{
    public function handle(TicketStatusChanged $event): void
    {
        if ($event->to !== TicketStatus::Resolved) {
            return;
        }

        $ticket = $event->ticket->loadMissing('creator');

        if ($ticket->creator?->is_active) {
            Notification::send($ticket->creator, new TicketResolvedNotification($ticket));
        }
    }
}
