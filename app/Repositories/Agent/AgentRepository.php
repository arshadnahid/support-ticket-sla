<?php

namespace App\Repositories\Agent;

use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Agent\AgentInterfaces\AgentInterface;
use Illuminate\Database\Eloquent\Collection;

class AgentRepository implements AgentInterface
{
    public function findActiveAgent(int $id, bool $lock = false): ?User
    {
        return User::query()
            ->withRole(UserRole::Agent)
            ->active()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->find($id);
    }

    public function availableWithWorkload(bool $lock = false): Collection
    {
        $agents = User::query()
            ->availableAgents()
            ->orderBy('id') // same lock order everywhere, so concurrent assignments can't deadlock
            ->select(['id', 'name', 'last_assigned_at'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        if ($agents->isEmpty()) {
            return $agents;
        }

        // One grouped query for everyone (uses the (assigned_to, status) index), not one per agent.
        $workload = Ticket::query()
            ->active()
            ->whereIn('assigned_to', $agents->modelKeys())
            ->groupBy('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')
            ->pluck('total', 'assigned_to');

        return $agents->each(
            fn (User $agent) => $agent->setAttribute('active_tickets_count', (int) ($workload[$agent->id] ?? 0))
        );
    }

    public function recordAssignment(User $agent): void
    {
        User::query()->whereKey($agent->id)->update(['last_assigned_at' => now()]);
    }
}
