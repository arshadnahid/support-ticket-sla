<?php

namespace App\Repositories\Agent\AgentInterfaces;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface AgentInterface
{
    /**
     * An enabled support agent by id, or null.
     */
    public function findActiveAgent(int $id): ?User;

    /**
     * Agents that can receive tickets right now (role agent, active, available),
     * each with an `active_tickets_count` attribute (open, in progress, waiting).
     *
     * With $lock the agent rows are locked (SELECT ... FOR UPDATE, in id order), so two
     * automatic assignments cannot choose from the same stale workload numbers.
     * Only call with $lock inside a transaction.
     *
     * @return Collection<int, User>
     */
    public function availableWithWorkload(bool $lock = false): Collection;

    /**
     * Remember that the agent was just given a ticket (assignment tie-break).
     */
    public function recordAssignment(User $agent): void;
}
