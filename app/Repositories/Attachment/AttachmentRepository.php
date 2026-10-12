<?php

namespace App\Repositories\Attachment;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Repositories\Attachment\AttachmentInterfaces\AttachmentInterface;
use Illuminate\Database\Eloquent\Collection;

class AttachmentRepository implements AttachmentInterface
{
    public function create(array $attributes): TicketAttachment
    {
        return TicketAttachment::create($attributes);
    }

    public function listFor(Ticket $ticket, User $user): Collection
    {
        return $ticket->attachments()
            ->visibleTo($user)
            ->with('uploader:id,name,email,role')
            ->orderBy('id')
            ->get();
    }
}
