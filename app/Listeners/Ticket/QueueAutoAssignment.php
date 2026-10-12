<?php

namespace App\Listeners\Ticket;

use App\Events\Ticket\TicketCreated;
use App\Jobs\AutoAssignTicket;

/**
 * New ticket -> queue its automatic assignment. Laravel finds this listener on its own
 * (it is in app/Listeners and type-hints the event), so no registration is needed.
 */
class QueueAutoAssignment
{
    public function handle(TicketCreated $event): void
    {
        if (config('tickets.assignment.auto_assign')) {
            AutoAssignTicket::dispatch($event->ticket->id);
        }
    }
}
