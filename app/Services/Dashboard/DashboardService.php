<?php

namespace App\Services\Dashboard;

use App\Enums\TicketPriority;
use App\Models\User;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;

/**
 * Builds the dashboard for one user. Every number is counted over the tickets that user may
 * see (Ticket::visibleTo), so a customer sees their own tickets, an agent their own plus the
 * unassigned queue, and an admin everything.
 *
 * SLA numbers and the per-agent table are staff-only, like the `sla` block on a ticket.
 */
class DashboardService
{
    public function __construct(private readonly TicketInterface $tickets) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $now = now();
        $counts = $this->tickets->dashboardCounts($user, $now);
        $byPriority = $this->tickets->activeCountByPriority($user);

        $summary = [
            'open_tickets' => $counts['open_tickets'],
            'unassigned' => $counts['unassigned'],
            'critical' => $counts['critical'],
            'resolved' => $counts['resolved'],
            'closed' => $counts['closed'],
        ];

        $dashboard = [
            'summary' => $summary,
            // Every priority is listed, including the ones with no tickets, so charts need no gap filling.
            'by_priority' => collect(TicketPriority::cases())
                ->mapWithKeys(fn (TicketPriority $p) => [$p->value => $byPriority[$p->value] ?? 0])
                ->all(),
        ];

        if ($user->isStaff()) {
            $dashboard['summary']['sla_breached'] = $counts['sla_breached'];
            $dashboard['summary']['sla_approaching'] = $counts['sla_approaching'];
            $dashboard['by_agent'] = $this->tickets->activeCountByAgent($user, $now);
        }

        return $dashboard;
    }
}
