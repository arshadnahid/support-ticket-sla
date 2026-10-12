<?php

namespace App\Services\Ticket\Assignment;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides which agent an unassigned ticket goes to. To change the rule (round-robin,
 * by category, by skill ...) write another class and bind it in AppServiceProvider.
 */
interface AssignmentStrategy
{
    /**
     * @param  Collection<int, User>  $agents  Available agents, each with `active_tickets_count`.
     * @return User|null The chosen agent, or null when nobody should get it.
     */
    public function choose(Ticket $ticket, Collection $agents): ?User;
}
