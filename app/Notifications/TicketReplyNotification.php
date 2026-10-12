<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * "Agent replied" (sent to the customer) and "Customer replied" (sent to the agent).
 * Never created for internal notes.
 */
class TicketReplyNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, private TicketReply $reply, private User $author)
    {
        parent::__construct($ticket);
    }

    protected function type(): string
    {
        return $this->author->isStaff() ? 'agent_replied' : 'customer_replied';
    }

    protected function subject(): string
    {
        return $this->author->isStaff() ? 'New reply from support' : 'Customer replied';
    }

    protected function message(): string
    {
        return "{$this->author->name} replied to ticket {$this->ticket->number}.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->line('"'.Str::limit($this->reply->body, 300).'"');
    }
}
