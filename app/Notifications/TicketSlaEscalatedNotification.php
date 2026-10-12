<?php

namespace App\Notifications;

use App\Enums\SlaState;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells staff that a ticket is close to, or past, its SLA deadline.
 * Sent by email and stored in the `notifications` table. Never sent to customers.
 */
class TicketSlaEscalatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly SlaState $state,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $breached = $this->state === SlaState::Breached;

        return (new MailMessage)
            ->subject(($breached ? 'SLA breached: ' : 'SLA warning: ').$this->ticket->number)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->summary())
            ->line('Subject: '.$this->ticket->subject)
            ->line('Priority: '.$this->ticket->priority->label())
            ->line('Deadline: '.$this->ticket->due_at?->toDayDateTimeString().' (UTC)')
            ->line('Assigned to: '.($this->ticket->assignee?->name ?? 'nobody yet'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->state === SlaState::Breached ? 'sla_breached' : 'sla_warning',
            'ticket_number' => $this->ticket->number,
            'state' => $this->state->value,
            'message' => $this->summary(),
            'due_at' => $this->ticket->due_at?->toIso8601String(),
        ];
    }

    private function summary(): string
    {
        return $this->state === SlaState::Breached
            ? "Ticket {$this->ticket->number} has passed its SLA deadline."
            : "Ticket {$this->ticket->number} is approaching its SLA deadline.";
    }
}
