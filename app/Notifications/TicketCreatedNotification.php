<?php

namespace App\Notifications;

use App\Models\Ticket;

/**
 * Tells the customer their ticket was received, and tells admins a new ticket arrived.
 */
class TicketCreatedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, private bool $forStaff)
    {
        parent::__construct($ticket);
    }

    protected function type(): string
    {
        return 'ticket_created';
    }

    protected function subject(): string
    {
        return $this->forStaff ? 'New ticket' : 'Ticket received';
    }

    protected function message(): string
    {
        return $this->forStaff
            ? "A new {$this->ticket->priority->label()} priority ticket {$this->ticket->number} was created."
            : "We received your ticket {$this->ticket->number}. We will get back to you soon.";
    }
}
