<?php

namespace App\Events\Ticket;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  Column => old and new value.
     *                                                                 Enough to write "Priority changed from Medium to High".
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $actor,
        public readonly array $changes,
    ) {}
}
