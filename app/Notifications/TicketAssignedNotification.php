<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;

/**
 * Tells the agent a ticket is now theirs, and tells the customer who is handling it.
 */
class TicketAssignedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, private User $assignee, private bool $forAssignee)
    {
        parent::__construct($ticket);
    }

    protected function type(): string
    {
        return 'ticket_assigned';
    }

    protected function subject(): string
    {
        return $this->forAssignee ? 'Ticket assigned to you' : 'Your ticket has an agent';
    }

    protected function message(): string
    {
        return $this->forAssignee
            ? "Ticket {$this->ticket->number} was assigned to you."
            : "Your ticket {$this->ticket->number} is now being handled by {$this->assignee->name}.";
    }
}
