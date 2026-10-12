<?php

namespace App\Jobs;

use App\Services\Ticket\TicketAssignmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Picks an agent for one ticket (see TicketAssignmentService::autoAssign).
 *
 * Runs on the queue so creating a ticket never waits for the assignment, and a busy
 * moment or a deadlock only delays the job instead of failing the request.
 *
 * Unique per ticket: the new-ticket listener and the scheduled sweep may both queue the
 * same ticket; only one copy waits in the queue at a time.
 */
class AutoAssignTicket implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Safety net: release the uniqueness lock even if a worker dies mid-job. */
    public int $uniqueFor = 300;

    public function __construct(public readonly int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    /**
     * Seconds to wait before attempt 2 and 3.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TicketAssignmentService $assignment): void
    {
        // Checked again here: the setting may have changed while the job was waiting.
        if (! config('tickets.assignment.auto_assign')) {
            return;
        }

        // Returns null when there is nothing to do (already taken, or no agent free right now).
        // That is not a failure; the scheduled sweep will queue the ticket again later.
        $assignment->autoAssign($this->ticketId);
    }
}
