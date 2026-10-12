<?php

namespace App\Repositories\Reply;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Repositories\Reply\ReplyInterfaces\ReplyInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ReplyRepository implements ReplyInterface
{
    /**
     * Loaded in one extra query each, so a page of replies never costs a query per reply.
     */
    private const RELATIONS = [
        'author:id,name,email,role',
        'attachments',
    ];

    public function create(array $attributes): TicketReply
    {
        return TicketReply::create($attributes);
    }

    public function paginateFor(Ticket $ticket, User $user, int $perPage): LengthAwarePaginator
    {
        return $ticket->replies()
            ->visibleTo($user)
            ->with(self::RELATIONS)
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function loadRelations(TicketReply $reply): TicketReply
    {
        return $reply->load(self::RELATIONS);
    }
}
