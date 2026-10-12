<?php

namespace App\Console\Commands;

use App\Jobs\AutoAssignTicket;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The safety net for automatic assignment. The listener handles each new ticket, but a
 * ticket can still be left waiting: every agent was unavailable, or a job failed. This
 * runs every minute (routes/console.php) and queues the waiting tickets again, most
 * urgent first. It does no assigning itself; the queue workers do.
 */
#[Signature('tickets:assign-unassigned {--limit= : Maximum number of tickets to queue (default: tickets.assignment.batch)}')]
#[Description('Queue automatic assignment for active tickets that still have no agent')]
class AssignUnassignedTickets extends Command
{
    public function handle(TicketInterface $tickets): int
    {
        if (! config('tickets.assignment.auto_assign')) {
            $this->components->info('Automatic assignment is disabled (TICKET_AUTO_ASSIGN=false).');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('tickets.assignment.batch'));

        $ids = $tickets->unassignedActiveIds($limit);

        foreach ($ids as $id) {
            AutoAssignTicket::dispatch($id);
        }

        $this->components->info(count($ids).' ticket(s) queued for assignment.');

        return self::SUCCESS;
    }
}
