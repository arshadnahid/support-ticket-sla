<?php

namespace App\Events\Ticket;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once a ticket edit is committed to the database (ShouldDispatchAfterCommit: held
 * until the surrounding transaction commits, dropped if it rolls back). Only fired when at
 * least one field really changed. No listeners yet; any added later should be queued so a
 * failure never turns an already-saved edit into an error for the user.
 */
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
