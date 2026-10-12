<?php

namespace App\Http\Resources\Ticket;

use App\Models\Ticket;
use App\Services\Sla\SlaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => ['value' => $this->priority->value, 'label' => $this->priority->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            // whenLoaded: a relation that was not eager-loaded is left out instead of triggering a
            // query, and an empty one (unassigned ticket) becomes null.
            'category' => $this->whenLoaded('category', fn ($category) => new CategoryResource($category)),
            'created_by' => $this->whenLoaded('creator', fn ($user) => new UserSummaryResource($user)),
            'assigned_to' => $this->whenLoaded('assignee', fn ($user) => new UserSummaryResource($user)),
            'assigned_at' => $this->assigned_at,
            'due_at' => $this->due_at,
            // SLA standing and escalation are internal: staff only.
            'sla' => $this->when($request->user()?->isStaff(), fn () => $this->slaBlock()),
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function slaBlock(): array
    {
        $sla = app(SlaService::class);
        $state = $sla->stateOf($this->resource);
        $remaining = $sla->minutesRemaining($this->resource);

        return [
            'state' => $state ? ['value' => $state->value, 'label' => $state->label()] : null,
            'warning_at' => $this->sla_warning_at,
            // Working minutes left until the deadline (null once the ticket is resolved or closed).
            'business_minutes_remaining' => $remaining !== null ? (int) round($remaining) : null,
            'escalation_level' => $this->escalation_level,
            'escalated_at' => $this->escalated_at,
        ];
    }
}
