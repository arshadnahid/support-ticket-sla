<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;

/**
 * The permission matrix in TicketPolicy, checked end to end through the API.
 */
class TicketAuthorizationTest extends TicketTestCase
{
    // ---- view -------------------------------------------------------------

    public function test_customer_can_view_own_ticket_but_not_someone_elses(): void
    {
        $own = $this->ticketFor($this->customer);
        $theirs = $this->ticketFor($this->otherCustomer);

        $this->as($this->customer)->getJson("/api/tickets/{$own->number}")
            ->assertOk()
            ->assertJsonPath('data.number', $own->number);

        $this->as($this->customer)->getJson("/api/tickets/{$theirs->number}")->assertForbidden();
    }

    public function test_agent_can_view_assigned_and_unassigned_tickets_but_not_other_agents(): void
    {
        $mine = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
        $unassigned = $this->ticketFor($this->customer);
        $others = $this->ticketFor($this->customer, ['assigned_to' => $this->otherAgent->id]);

        $this->as($this->agent)->getJson("/api/tickets/{$mine->number}")->assertOk();
        $this->as($this->agent)->getJson("/api/tickets/{$unassigned->number}")->assertOk();
        $this->as($this->agent)->getJson("/api/tickets/{$others->number}")->assertForbidden();
    }

    public function test_admin_can_view_any_ticket(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->admin)->getJson("/api/tickets/{$ticket->number}")->assertOk();
    }

    public function test_unknown_ticket_number_returns_404_in_the_standard_format(): void
    {
        $this->as($this->admin)
            ->getJson('/api/tickets/TKT-2026-999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error');
    }

    public function test_guest_gets_401_before_any_ticket_lookup(): void
    {
        $ticket = $this->ticketFor($this->customer);

        // 401 (not 404/403) so guests cannot probe which ticket numbers exist.
        $this->getJson("/api/tickets/{$ticket->number}")->assertUnauthorized();
        $this->getJson('/api/tickets/TKT-2026-999999')->assertUnauthorized();
    }

    // ---- update -----------------------------------------------------------

    public function test_customer_can_edit_own_open_ticket_but_not_priority(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->customer)
            ->patchJson("/api/tickets/{$ticket->number}", ['subject' => 'Better subject'])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Better subject');

        $this->as($this->customer)
            ->patchJson("/api/tickets/{$ticket->number}", ['priority' => 'critical'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('priority');
    }

    public function test_customer_cannot_edit_once_work_has_started(): void
    {
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::InProgress, 'assigned_to' => $this->agent->id]);

        $this->as($this->customer)
            ->patchJson("/api/tickets/{$ticket->number}", ['subject' => 'Too late'])
            ->assertForbidden();
    }

    public function test_customer_cannot_edit_someone_elses_ticket(): void
    {
        $ticket = $this->ticketFor($this->otherCustomer);

        $this->as($this->customer)
            ->patchJson("/api/tickets/{$ticket->number}", ['subject' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_agent_can_edit_only_tickets_assigned_to_them(): void
    {
        $mine = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
        $unassigned = $this->ticketFor($this->customer);

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$mine->number}", ['priority' => 'critical'])
            ->assertOk()
            ->assertJsonPath('data.priority.value', 'critical');

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$unassigned->number}", ['priority' => 'critical'])
            ->assertForbidden();
    }

    public function test_admin_can_edit_any_active_ticket(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->admin)
            ->patchJson("/api/tickets/{$ticket->number}", ['priority' => 'low'])
            ->assertOk()
            ->assertJsonPath('data.priority.value', 'low');
    }

    public function test_closed_tickets_cannot_be_edited_by_anyone(): void
    {
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'assigned_to' => $this->agent->id]);

        foreach ([$this->admin, $this->agent, $this->customer] as $user) {
            $this->as($user)
                ->patchJson("/api/tickets/{$ticket->number}", ['subject' => 'Edit'])
                ->assertForbidden();
        }
    }

    // ---- status -----------------------------------------------------------

    public function test_only_the_assigned_agent_or_an_admin_can_resolve(): void
    {
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::InProgress, 'assigned_to' => $this->agent->id]);

        $this->as($this->otherAgent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertForbidden();
        $this->as($this->customer)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertForbidden();
        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertOk();
    }

    public function test_customer_can_close_own_ticket_but_not_set_other_statuses(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->customer)
            ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => 'in_progress'])
            ->assertForbidden();

        $this->as($this->customer)
            ->patchJson("/api/tickets/{$ticket->number}/close")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'closed');
    }

    public function test_customer_cannot_close_someone_elses_ticket(): void
    {
        $ticket = $this->ticketFor($this->otherCustomer);

        $this->as($this->customer)->patchJson("/api/tickets/{$ticket->number}/close")->assertForbidden();
    }

    public function test_inactive_user_with_valid_token_is_blocked(): void
    {
        $token = auth('api')->login($this->customer);
        $this->customer->update(['is_active' => false]);
        auth('api')->forgetUser();

        $this->withToken($token)->getJson('/api/tickets')->assertForbidden();
    }
}
