<?php

namespace App\Repositories\Ticket;

use App\Enums\TicketListScope;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class TicketRepository implements TicketInterface
{
    /**
     * Relations (with only the columns we show) loaded in one extra query each,
     * so a list of 15 tickets costs 4 queries, not 1 + 45.
     */
    private const RELATIONS = [
        'category:id,name,slug',
        'creator:id,name,email',
        'assignee:id,name,email',
    ];

    public function create(array $attributes): Ticket
    {
        return Ticket::create($attributes);
    }

    public function lockForUpdate(int $id): Ticket
    {
        return Ticket::query()->lockForUpdate()->findOrFail($id);
    }

    public function update(Ticket $ticket, array $attributes): Ticket
    {
        $ticket->update($attributes);

        return $ticket;
    }

    public function paginateFor(User $user, TicketListScope $scope, int $perPage): LengthAwarePaginator
    {
        return Ticket::query()
            ->visibleTo($user)
            ->with(self::RELATIONS)
            ->tap(fn (Builder $query) => $this->applyScope($query, $scope))
            ->paginate($perPage);
    }

    public function unassignedActiveIds(int $limit): array
    {
        // CASE priority WHEN 'critical' THEN 4 ... : built from the enum so the order can't drift.
        $urgency = 'CASE priority '.collect(TicketPriority::cases())
            ->map(fn (TicketPriority $p) => "WHEN '{$p->value}' THEN {$p->weight()}")
            ->implode(' ').' ELSE 0 END';

        return Ticket::query()
            ->active()
            ->whereNull('assigned_to')
            ->orderByRaw("$urgency DESC")
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    public function loadRelations(Ticket $ticket): Ticket
    {
        return $ticket->load(self::RELATIONS);
    }

    private function applyScope(Builder $query, TicketListScope $scope): void
    {
        match ($scope) {
            // Upcoming first: earliest SLA deadline on top, tickets without one last.
            TicketListScope::Active => $query->active()
                ->orderByRaw('due_at is null')
                ->orderBy('due_at')
                ->orderBy('id'),

            // Most recently finished first.
            TicketListScope::Finished => $query->finished()
                ->orderByDesc('updated_at')
                ->orderByDesc('id'),

            TicketListScope::All => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
