<?php

namespace App\Listeners\Ticket;

use App\Enums\UserRole;
use App\Events\Ticket\TicketCreated;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * New ticket: the customer gets a confirmation, all active admins hear about it. (The agent
 * is told separately, by NotifyTicketAssigned, once the ticket is assigned.)
 */
class NotifyTicketCreated implements ShouldQueue
{
    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket;

        if ($event->actor->is_active) {
            Notification::send($event->actor, new TicketCreatedNotification($ticket, forStaff: false));
        }

        Notification::send(
            User::query()->withRole(UserRole::Admin)->active()->get(),
            new TicketCreatedNotification($ticket, forStaff: true),
        );
    }
}
