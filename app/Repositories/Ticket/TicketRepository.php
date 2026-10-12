<?php

namespace App\Repositories\Ticket;

use App\DTOs\Ticket\TicketListFilters;
use App\Enums\TicketListScope;
use App\Enums\TicketPriority;
use App\Enums\TicketSort;
use App\Models\Ticket;
use App\Enums\TicketStatus;
use App\Models\User;
use Carbon\CarbonInterface;
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

    public function paginateFor(User $user, TicketListScope $scope, int $perPage, TicketListFilters $filters): LengthAwarePaginator
    {
        return Ticket::query()
            ->visibleTo($user)
            ->with(self::RELATIONS)
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->tap(fn (Builder $query) => $this->applyScope($query, $scope, $filters->sort))
            ->paginate($perPage)
            // So next_page_url / prev_page_url keep the search and filters.
            ->withQueryString();
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

    public function slaEscalationCandidateIds(int $limit): array
    {
        $now = now();

        return Ticket::query()
            ->active()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $b) => $b->where('escalation_level', '<', 2)->where('due_at', '<', $now))
                ->orWhere(fn (Builder $w) => $w->where('escalation_level', '<', 1)->where('sla_warning_at', '<=', $now)))
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    public function dashboardCounts(User $user, CarbonInterface $now): array
    {
        $active = array_column(TicketStatus::active(), 'value');
        $in = implode(',', array_fill(0, count($active), '?'));
        $at = $now->toDateTimeString();

        // One query for every headline number: each SUM(CASE ...) counts the tickets matching one rule.
        $row = Ticket::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status IN ($in) THEN 1 ELSE 0 END), 0) AS open_tickets,
                 COALESCE(SUM(CASE WHEN status IN ($in) AND assigned_to IS NULL THEN 1 ELSE 0 END), 0) AS unassigned,
                 COALESCE(SUM(CASE WHEN status IN ($in) AND priority = ? THEN 1 ELSE 0 END), 0) AS critical,
                 COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS resolved,
                 COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS closed,
                 COALESCE(SUM(CASE WHEN status IN ($in) AND due_at < ? THEN 1 ELSE 0 END), 0) AS sla_breached,
                 COALESCE(SUM(CASE WHEN status IN ($in) AND due_at >= ? AND sla_warning_at <= ? THEN 1 ELSE 0 END), 0) AS sla_approaching",
                [
                    ...$active,
                    ...$active,
                    ...$active, TicketPriority::Critical->value,
                    TicketStatus::Resolved->value,
                    TicketStatus::Closed->value,
                    ...$active, $at,
                    ...$active, $at, $at,
                ],
            )
            ->first();

        return array_map('intval', (array) $row);
    }

    public function activeCountByPriority(User $user): array
    {
        return Ticket::query()
            ->visibleTo($user)
            ->active()
            ->toBase()
            ->selectRaw('priority, COUNT(*) AS total')
            ->groupBy('priority')
            ->pluck('total', 'priority')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    public function activeCountByAgent(User $user, CarbonInterface $now): array
    {
        $at = $now->toDateTimeString();

        return Ticket::query()
            ->visibleTo($user)
            ->active()
            ->whereNotNull('tickets.assigned_to')
            ->join('users', 'users.id', '=', 'tickets.assigned_to')
            ->toBase()
            ->selectRaw('users.id AS agent_id, users.name AS name, COUNT(*) AS active_tickets,
                COALESCE(SUM(CASE WHEN tickets.due_at < ? THEN 1 ELSE 0 END), 0) AS sla_breached', [$at])
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('active_tickets')
            ->orderBy('users.name')
            ->get()
            ->map(fn ($r) => [
                'agent_id' => (int) $r->agent_id,
                'name' => $r->name,
                'active_tickets' => (int) $r->active_tickets,
                'sla_breached' => (int) $r->sla_breached,
            ])
            ->all();
    }

    public function loadRelations(Ticket $ticket): Ticket
    {
        return $ticket->load(self::RELATIONS);
    }

    /**
     * An explicit ?sort= replaces the scope's default order; the scope's own filter still applies.
     */
    private function applySort(Builder $query, TicketListScope $scope, TicketSort $sort): void
    {
        match ($scope) {
            TicketListScope::Active => $query->active(),
            TicketListScope::Finished => $query->finished(),
            TicketListScope::All => null,
        };

        match ($sort) {
            TicketSort::SlaDeadline => $this->bySlaDeadline($query),
            TicketSort::CreatedAsc => $query->orderBy('created_at')->orderBy('id'),
            TicketSort::CreatedDesc => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * Earliest SLA deadline first, tickets without one last.
     */
    private function bySlaDeadline(Builder $query): void
    {
        $query->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id');
    }

    /**
     * Filters only narrow the list; who may see a ticket is Ticket::visibleTo(), applied before this.
     */
    private function applyFilters(Builder $query, TicketListFilters $f): void
    {
        $query
            ->when($f->number, fn (Builder $q, string $number) => $q->where('number', $number))
            ->when($f->keyword, fn (Builder $q, string $keyword) => $this->matchKeyword($q, $keyword))
            ->when($f->status, fn (Builder $q) => $q->where('status', $f->status))
            ->when($f->priority, fn (Builder $q) => $q->where('priority', $f->priority))
            ->when($f->categoryId, fn (Builder $q, int $id) => $q->where('category_id', $id))
            ->when($f->assignedTo, fn (Builder $q, int $id) => $q->where('assigned_to', $id))
            ->when($f->unassigned, fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when($f->customerId, fn (Builder $q, int $id) => $q->where('created_by', $id))
            ->when($f->createdFrom, fn (Builder $q, $from) => $q->where('created_at', '>=', $from))
            ->when($f->createdTo, fn (Builder $q, $to) => $q->where('created_at', '<=', $to))
            ->when($f->sla, fn (Builder $q) => $q->inSlaState($f->sla));
    }

    /**
     * Words anywhere in the number, subject or description. `%`, `_` and `!` typed by the user are
     * escaped, so they are searched for literally instead of acting as wildcards.
     */
    private function matchKeyword(Builder $query, string $keyword): void
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw("number like ? escape '!'", [$like])
            ->orWhereRaw("subject like ? escape '!'", [$like])
            ->orWhereRaw("description like ? escape '!'", [$like]));
    }

    private function applyScope(Builder $query, TicketListScope $scope, ?TicketSort $sort): void
    {
        if ($sort !== null) {
            $this->applySort($query, $scope, $sort);

            return;
        }

        match ($scope) {
            // Upcoming first: earliest SLA deadline on top, tickets without one last.
            TicketListScope::Active => $query->active()->tap($this->bySlaDeadline(...)),

            // Most recently finished first.
            TicketListScope::Finished => $query->finished()
                ->orderByDesc('updated_at')
                ->orderByDesc('id'),

            TicketListScope::All => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
