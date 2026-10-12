<?php

namespace App\Services\Ticket\Assignment;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The chosen automatic assignment rule:
 *
 *  1. only agents who are active and marked available (the caller already filtered these)
 *  2. the agent with the FEWEST active tickets (open, in progress, waiting for customer)
 *  3. tie: the agent who has gone longest without receiving a ticket (never-assigned first),
 *     which makes equal-load agents take turns, round-robin style
 *  4. last tie: the lowest id, so the result is deterministic
 *
 * Why this rule: it keeps load even without anyone having to track a rotation, it reacts
 * immediately when someone is busy or finishes tickets, and it needs no per-category setup.
 */
class LeastWorkloadStrategy implements AssignmentStrategy
{
    public function choose(Ticket $ticket, Collection $agents): ?User
    {
        return $agents
            ->sort(fn (User $a, User $b) => [
                $a->active_tickets_count,
                $a->last_assigned_at?->getTimestamp() ?? 0, // null (never assigned) sorts first
                $a->id,
            ] <=> [
                $b->active_tickets_count,
                $b->last_assigned_at?->getTimestamp() ?? 0,
                $b->id,
            ])
            ->first();
    }
}
