<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;

class DashboardTest extends TicketTestCase
{
    /**
     * Active: 2 critical (one assigned to agent and breached), 1 high unassigned, 1 low assigned
     * to otherAgent and approaching. Finished: 1 resolved, 1 closed. Plus one ticket of another customer.
     */
    private function seedTickets(): void
    {
        $this->ticketFor($this->customer, [
            'priority' => TicketPriority::Critical, 'assigned_to' => $this->agent->id,
            'due_at' => now()->subHour(), 'sla_warning_at' => now()->subHours(2),
        ]);
        $this->ticketFor($this->customer, [
            'priority' => TicketPriority::Critical, 'status' => TicketStatus::InProgress,
            'due_at' => now()->addHours(5), 'sla_warning_at' => now()->addHours(4),
        ]);
        $this->ticketFor($this->customer, [
            'priority' => TicketPriority::High,
            'due_at' => now()->addHours(5), 'sla_warning_at' => now()->addHours(4),
        ]);
        $this->ticketFor($this->otherCustomer, [
            'priority' => TicketPriority::Low, 'assigned_to' => $this->otherAgent->id,
            'due_at' => now()->addHour(), 'sla_warning_at' => now()->subMinute(),
        ]);
        $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'resolved_at' => now()]);
        $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'closed_at' => now()]);
    }

    public function test_admin_sees_every_number(): void
    {
        $this->seedTickets();

        $this->as($this->admin)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.open_tickets', 4)
            ->assertJsonPath('data.summary.unassigned', 2)
            ->assertJsonPath('data.summary.critical', 2)
            ->assertJsonPath('data.summary.resolved', 1)
            ->assertJsonPath('data.summary.closed', 1)
            ->assertJsonPath('data.summary.sla_breached', 1)
            ->assertJsonPath('data.summary.sla_approaching', 1)
            ->assertJsonPath('data.by_priority', ['low' => 1, 'medium' => 0, 'high' => 1, 'critical' => 2])
            ->assertJsonCount(2, 'data.by_agent')
            ->assertJsonPath('data.by_agent.0.active_tickets', 1);
    }

    public function test_agent_sees_own_tickets_and_the_unassigned_queue(): void
    {
        $this->seedTickets();

        $this->as($this->agent)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.open_tickets', 3) // own + 2 unassigned, not otherAgent's
            ->assertJsonPath('data.summary.unassigned', 2)
            ->assertJsonPath('data.summary.sla_breached', 1)
            ->assertJsonPath('data.summary.sla_approaching', 0)
            ->assertJsonCount(1, 'data.by_agent')
            ->assertJsonPath('data.by_agent.0.agent_id', $this->agent->id)
            ->assertJsonPath('data.by_agent.0.sla_breached', 1);
    }

    public function test_customer_sees_only_own_tickets_and_no_sla_or_agent_data(): void
    {
        $this->seedTickets();

        $this->as($this->customer)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.open_tickets', 3)
            ->assertJsonPath('data.summary.resolved', 1)
            ->assertJsonPath('data.by_priority.low', 0)
            ->assertJsonMissingPath('data.summary.sla_breached')
            ->assertJsonMissingPath('data.summary.sla_approaching')
            ->assertJsonMissingPath('data.by_agent');
    }

    public function test_empty_dashboard_is_all_zeros(): void
    {
        $this->as($this->admin)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.open_tickets', 0)
            ->assertJsonPath('data.by_priority', ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0])
            ->assertJsonPath('data.by_agent', []);
    }

    public function test_guests_cannot_see_the_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }
}
