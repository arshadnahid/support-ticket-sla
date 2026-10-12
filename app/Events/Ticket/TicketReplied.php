<?php

namespace App\Events\Ticket;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reply or internal note was added. Later modules hang notifications ("New reply") and the
 * SLA first-response clock here. Listeners MUST NOT tell the customer about internal notes:
 * check `$reply->is_internal`.
 */
class TicketReplied implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
        public readonly User $author,
    ) {}
}
