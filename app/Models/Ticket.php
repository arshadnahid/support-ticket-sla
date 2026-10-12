<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'number', 'subject', 'description', 'priority', 'status',
    'category_id', 'created_by', 'assigned_to', 'assigned_at',
    'due_at', 'resolved_at', 'closed_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'priority' => TicketPriority::class,
            'status' => TicketStatus::class,
            'category_id' => 'integer',
            'created_by' => 'integer',
            'assigned_to' => 'integer',
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Routes use the ticket number (TKT-2026-000001), not the sequential id,
     * so ids are not exposed or guessable.
     */
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Tickets the user may see. Keep in sync with TicketPolicy::view().
     *
     *  - admin:    everything
     *  - agent:    tickets assigned to them, plus the unassigned queue they can pick from
     *  - customer: only tickets they created
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin => null,
            UserRole::Agent => $query->where(
                fn (Builder $q) => $q->where('assigned_to', $user->id)->orWhereNull('assigned_to')
            ),
            UserRole::Customer => $query->where('created_by', $user->id),
        };
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', array_column(TicketStatus::active(), 'value'));
    }

    #[Scope]
    protected function finished(Builder $query): void
    {
        $query->whereIn('status', array_column(TicketStatus::finished(), 'value'));
    }
}
