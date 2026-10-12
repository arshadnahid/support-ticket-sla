<?php

namespace App\Notifications;

/**
 * Tells the customer their ticket was resolved.
 */
class TicketResolvedNotification extends TicketNotification
{
    protected function type(): string
    {
        return 'ticket_resolved';
    }

    protected function subject(): string
    {
        return 'Ticket resolved';
    }

    protected function message(): string
    {
        return "Your ticket {$this->ticket->number} was resolved.";
    }
}
