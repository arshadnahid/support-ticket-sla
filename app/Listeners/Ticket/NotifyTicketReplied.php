<?php

namespace App\Listeners\Ticket;

use App\Enums\UserRole;
use App\Events\Ticket\TicketReplied;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketReplyNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a reply:
 *
 *   staff reply     the customer
 *   customer reply  the assigned agent; all admins instead if nobody has the ticket yet
 *   internal note   nobody (notes are for staff to read on the ticket, and must never reach the customer)
 *
 * The author is never notified about their own reply.
 */
class NotifyTicketReplied
{
    public function handle(TicketReplied $event): void
    {
        if ($event->reply->is_internal) {
            return;
        }

        $ticket = $event->ticket->loadMissing(['creator', 'assignee']);

        $recipients = $event->author->isStaff()
            ? collect([$ticket->creator])
            : $this->staffFor($ticket);

        $recipients = $recipients
            ->filter(fn (?User $user) => $user?->is_active && $user->id !== $event->author->id)
            ->unique('id');

        Notification::send($recipients, new TicketReplyNotification($ticket, $event->reply, $event->author));
    }

    /**
     * @return Collection<int, User>
     */
    private function staffFor(Ticket $ticket): Collection
    {
        if ($ticket->assignee?->is_active) {
            return collect([$ticket->assignee]);
        }

        return User::query()->withRole(UserRole::Admin)->active()->get();
    }
}
