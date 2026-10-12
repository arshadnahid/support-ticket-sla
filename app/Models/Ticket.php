<?php

namespace App\Models;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Carbon\CarbonInterface;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number', 'subject', 'description', 'priority', 'status',
    'category_id', 'created_by', 'assigned_to', 'assigned_at',
    'due_at', 'sla_warning_at', 'escalation_level', 'escalated_at', 'resolved_at', 'closed_at',
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
            'sla_warning_at' => 'datetime',
            'escalation_level' => 'integer',
            'escalated_at' => 'datetime',
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

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
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

    /**
     * Active tickets in the given SLA state, judged at $now. Mirrors SlaService::stateOf():
     * breached = past the deadline, approaching = past the warning time but not the deadline.
     * Tickets without a deadline match no state.
     */
    #[Scope]
    protected function inSlaState(Builder $query, SlaState $state, ?CarbonInterface $now = null): void
    {
        $now ??= now();

        $query->active()->whereNotNull('due_at')->where(match ($state) {
            SlaState::Breached => fn (Builder $q) => $q->where('due_at', '<', $now),
            SlaState::Approaching => fn (Builder $q) => $q->where('due_at', '>=', $now)->where('sla_warning_at', '<=', $now),
            SlaState::Within => fn (Builder $q) => $q->where('due_at', '>=', $now)
                ->where(fn (Builder $w) => $w->whereNull('sla_warning_at')->orWhere('sla_warning_at', '>', $now)),
        });
    }

    #[Scope]
    protected function finished(Builder $query): void
    {
        $query->whereIn('status', array_column(TicketStatus::finished(), 'value'));
    }
}
