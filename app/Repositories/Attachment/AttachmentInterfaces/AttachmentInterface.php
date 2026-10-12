<?php

namespace App\Repositories\Attachment\AttachmentInterfaces;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface AttachmentInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TicketAttachment;

    /**
     * Every file on the ticket (and on its replies) that $user may see, oldest first.
     * Files on internal notes are left out unless $user is staff.
     *
     * @return Collection<int, TicketAttachment>
     */
    public function listFor(Ticket $ticket, User $user): Collection;
}
