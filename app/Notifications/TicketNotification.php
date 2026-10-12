<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape of the ticket notifications: queued, sent by email and stored in the
 * `notifications` table (for an in-app inbox). A subclass only says WHAT happened:
 * a `type` key, a short `subject()` and a `message()` sentence.
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Ticket $ticket) {}

    /** Machine key stored with the notification, e.g. "ticket_assigned". */
    abstract protected function type(): string;

    abstract protected function subject(): string;

    abstract protected function message(): string;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject().': '.$this->ticket->number)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->message())
            ->line('Ticket: '.$this->ticket->number.' - '.$this->ticket->subject)
            ->line('Priority: '.$this->ticket->priority->label().' | Status: '.$this->ticket->status->label());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'ticket_number' => $this->ticket->number,
            'message' => $this->message(),
        ];
    }
}
