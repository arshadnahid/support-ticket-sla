<?php

namespace App\Events\Ticket;

use App\Enums\AssignmentMethod;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a ticket gets (or changes) its assignee. Later modules use it to notify the
 * new agent and to write "Assigned to Agent B" in the activity history.
 */
class TicketAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $assignee,
        public readonly AssignmentMethod $method,
        public readonly ?User $actor,            // null = the system (automatic assignment)
        public readonly ?int $previousAssigneeId, // set when a ticket is reassigned
    ) {}
}
