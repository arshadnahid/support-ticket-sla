<?php

namespace App\Listeners\Ticket;

use App\Events\Ticket\TicketCreated;
use App\Jobs\AutoAssignTicket;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * New ticket -> queue its automatic assignment. Laravel finds this listener on its own
 * (it is in app/Listeners and type-hints the event), so no registration is needed.
 */
class QueueAutoAssignment implements ShouldQueue
{
    public function handle(TicketCreated $event): void
    {
        if (config('tickets.assignment.auto_assign')) {
            AutoAssignTicket::dispatch($event->ticket->id);
        }
    }
}
