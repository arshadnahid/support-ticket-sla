<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['uuid', 'ticket_id', 'reply_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class TicketAttachment extends Model
{
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'reply_id' => 'integer',
            'uploaded_by' => 'integer',
            'size' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'reply_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Files on an internal note are as private as the note itself. Keep in sync with
     * TicketReply::visibleTo().
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->isStaff()) {
            $query->where(fn (Builder $q) => $q
                ->whereNull('reply_id')
                ->orWhereHas('reply', fn (Builder $r) => $r->where('is_internal', false)));
        }
    }

    /**
     * Whether this one file may be seen by $user (same rule as the scope above).
     */
    public function isVisibleTo(User $user): bool
    {
        return $user->isStaff() || $this->reply_id === null || ! $this->reply->is_internal;
    }
}
