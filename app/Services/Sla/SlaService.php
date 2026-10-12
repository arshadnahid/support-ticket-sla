<?php

namespace App\Services\Sla;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns the rules in config/sla.php into deadlines and states.
 *
 * Two timestamps are stored on every ticket when it is created (or its priority changes):
 *
 *   due_at          the resolution deadline
 *   sla_warning_at  the moment it starts to be "approaching" (warning_percent of the SLA used)
 *
 * With both stored, the state of a ticket is a plain comparison with "now". No business-hours
 * maths runs on read, and "all breached tickets" is an ordinary indexed query.
 */
class SlaService
{
    public function __construct(private readonly BusinessHours $hours) {}

    /**
     * Working minutes the priority is allowed to take.
     */
    public function minutesFor(TicketPriority $priority): float
    {
        $hours = config('sla.resolution_hours.'.$priority->value);

        return (float) ($hours ?? config('sla.resolution_hours.'.TicketPriority::Medium->value)) * 60;
    }

    /**
     * Deadline and warning time for a ticket of this priority that starts at $start.
     *
     * @return array{due_at: CarbonImmutable, sla_warning_at: CarbonImmutable}
     */
    public function deadlinesFor(TicketPriority $priority, CarbonInterface $start): array
    {
        $minutes = $this->minutesFor($priority);

        return [
            'due_at' => $this->hours->addMinutes($start, $minutes),
            'sla_warning_at' => $this->hours->addMinutes($start, $minutes * config('sla.warning_percent') / 100),
        ];
    }

    /**
     * New SLA columns for an existing ticket, counted from the time it was created (used when
     * the priority changes and by tickets:recalculate-sla). The escalation level never goes up
     * here, but goes down when the new deadline is further away, so the ticket can be
     * escalated again properly.
     *
     * @return array<string, mixed>
     */
    public function recalculation(Ticket $ticket): array
    {
        $dates = $this->deadlinesFor($ticket->priority, $ticket->created_at);
        $rank = $this->stateOf((clone $ticket)->forceFill($dates))?->rank() ?? 0;

        return $dates + ['escalation_level' => min($ticket->escalation_level, $rank)];
    }

    /**
     * Active tickets: how they stand right now. Resolved or closed tickets: whether they were
     * finished in time. Null when the ticket has no deadline (created before SLAs existed).
     */
    public function stateOf(Ticket $ticket, ?CarbonInterface $now = null): ?SlaState
    {
        if ($ticket->due_at === null) {
            return null;
        }

        $finishedAt = $ticket->resolved_at ?? $ticket->closed_at;

        if (! $ticket->status->isActive() && $finishedAt !== null) {
            return $finishedAt->greaterThan($ticket->due_at) ? SlaState::Breached : SlaState::Within;
        }

        $now ??= now();

        return match (true) {
            $now->greaterThan($ticket->due_at) => SlaState::Breached,
            $ticket->sla_warning_at !== null && $now->greaterThanOrEqualTo($ticket->sla_warning_at) => SlaState::Approaching,
            default => SlaState::Within,
        };
    }

    /**
     * Working minutes left until the deadline; null if there is none or the ticket is finished.
     */
    public function minutesRemaining(Ticket $ticket, ?CarbonInterface $now = null): ?float
    {
        if ($ticket->due_at === null || ! $ticket->status->isActive()) {
            return null;
        }

        return $this->hours->minutesBetween($now ?? now(), $ticket->due_at);
    }
}
