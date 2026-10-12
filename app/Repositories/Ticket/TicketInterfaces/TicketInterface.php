<?php

namespace App\Repositories\Ticket\TicketInterfaces;

use App\DTOs\Ticket\TicketListFilters;
use App\Enums\TicketListScope;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;

interface TicketInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Ticket;

    /**
     * Re-read the ticket with a row lock (SELECT ... FOR UPDATE). Call inside a transaction.
     */
    public function lockForUpdate(int $id): Ticket;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Ticket $ticket, array $attributes): Ticket;

    /**
     * One page of the tickets $user is allowed to see, relations eager-loaded.
     *
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginateFor(User $user, TicketListScope $scope, int $perPage, TicketListFilters $filters): LengthAwarePaginator;

    /**
     * Ids of active tickets nobody has taken yet, most urgent first (critical before low),
     * then oldest first. Feeds the scheduled automatic-assignment sweep.
     *
     * @return list<int>
     */
    public function unassignedActiveIds(int $limit): array;

    /**
     * Ids of active tickets that have reached an SLA level (approaching or breached) they have
     * not been escalated for yet, most overdue first. Feeds the scheduled escalation sweep.
     *
     * @return list<int>
     */
    public function slaEscalationCandidateIds(int $limit): array;

    /**
     * Headline numbers for the dashboard, over the tickets $user may see, judged at $now:
     * open_tickets, unassigned, critical, sla_breached and sla_approaching (all active tickets
     * only), plus resolved and closed.
     *
     * @return array<string, int>
     */
    public function dashboardCounts(User $user, CarbonInterface $now): array;

    /**
     * Active tickets per priority, over the tickets $user may see: priority value => count
     * (priorities with no tickets are absent).
     *
     * @return array<string, int>
     */
    public function activeCountByPriority(User $user): array;

    /**
     * Active tickets per assigned agent, over the tickets $user may see (unassigned tickets are
     * not included), busiest first.
     *
     * @return list<array{agent_id: int, name: string, active_tickets: int, sla_breached: int}>
     */
    public function activeCountByAgent(User $user, CarbonInterface $now): array;

    /**
     * Eager-load everything TicketResource needs.
     */
    public function loadRelations(Ticket $ticket): Ticket;
}
