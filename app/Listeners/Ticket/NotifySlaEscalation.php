<?php

namespace App\Listeners\Ticket;

use App\Enums\SlaState;
use App\Enums\UserRole;
use App\Events\Ticket\TicketSlaEscalated;
use App\Models\User;
use App\Notifications\TicketSlaEscalatedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about an escalation:
 *
 *   approaching  the assigned agent; admins instead if nobody has the ticket yet
 *   breached     the assigned agent AND all admins
 *
 * Customers are never notified. Laravel finds this listener by its type-hint.
 */
class NotifySlaEscalation
{
    public function handle(TicketSlaEscalated $event): void
    {
        $ticket = $event->ticket->loadMissing('assignee');

        $recipients = collect();

        if ($ticket->assignee?->is_active) {
            $recipients->push($ticket->assignee);
        }

        if ($event->state === SlaState::Breached || $recipients->isEmpty()) {
            $recipients = $recipients->merge(User::query()->withRole(UserRole::Admin)->active()->get());
        }

        Notification::send($recipients->unique('id'), new TicketSlaEscalatedNotification($ticket, $event->state));
    }
}
