<?php

namespace App\Repositories\Reply\ReplyInterfaces;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface ReplyInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TicketReply;

    /**
     * One page of the conversation, oldest first, with internal notes left out unless
     * $user is staff.
     *
     * @return LengthAwarePaginator<int, TicketReply>
     */
    public function paginateFor(Ticket $ticket, User $user, int $perPage): LengthAwarePaginator;

    public function loadRelations(TicketReply $reply): TicketReply;
}
