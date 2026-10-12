<?php

namespace App\Repositories\Ticket\TicketInterfaces;

use App\DTOs\Ticket\TicketListFilters;
use App\Enums\TicketListScope;
use App\Models\Ticket;
use App\Models\User;
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
     * Eager-load everything TicketResource needs.
     */
    public function loadRelations(Ticket $ticket): Ticket;
}
