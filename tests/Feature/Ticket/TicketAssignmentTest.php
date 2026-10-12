<?php

namespace Tests\Feature\Ticket;

use App\Enums\AssignmentMethod;
use App\Enums\TicketStatus;
use App\Events\Ticket\TicketAssigned;
use App\Exceptions\TicketAssignmentException;
use App\Models\User;
use App\Services\Ticket\TicketAssignmentService;
use Illuminate\Support\Facades\Event;

/**
 * Manual assignment (admin) and accepting from the queue (agent), including the
 * "two agents accept at once" rule. Automatic assignment is not covered here.
 */
class TicketAssignmentTest extends TicketTestCase
{
    // ---- admin assigns ------------------------------------------------------

    public function test_admin_can_assign_a_ticket_to_an_agent(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->agent->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $this->agent->id)
            ->assertJsonPath('message', 'Ticket assigned');

        $fresh = $ticket->fresh();
        $this->assertSame($this->agent->id, $fresh->assigned_to);
        $this->assertNotNull($fresh->assigned_at);
        $this->assertNotNull($this->agent->fresh()->last_assigned_at);

        Event::assertDispatched(
            TicketAssigned::class,
            fn ($e) => $e->method === AssignmentMethod::Manual
                && $e->assignee->is($this->agent)
                && $e->actor->is($this->admin)
                && $e->previousAssigneeId === null
        );
    }

    public function test_admin_can_reassign_and_the_previous_agent_is_recorded(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->otherAgent->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $this->otherAgent->id);

        Event::assertDispatched(TicketAssigned::class, fn ($e) => $e->previousAssigneeId === $this->agent->id);
    }

    public function test_assigning_to_the_current_agent_changes_nothing(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->agent->id])
            ->assertOk();

        Event::assertNotDispatched(TicketAssigned::class);
    }

    public function test_admin_can_assign_to_an_agent_marked_unavailable(): void
    {
        $away = User::factory()->agent()->unavailable()->create();
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $away->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $away->id);
    }

    public function test_only_active_agents_can_be_assigned(): void
    {
        $ticket = $this->ticketFor($this->customer);
        $inactive = User::factory()->agent()->inactive()->create();

        foreach ([$this->customer->id, $this->admin->id, $inactive->id, 99999] as $notAnAgent) {
            $this->as($this->admin)
                ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $notAnAgent])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('agent_id');
        }

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('agent_id');

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_agents_and_customers_cannot_assign(): void
    {
        $ticket = $this->ticketFor($this->customer);

        foreach ([$this->agent, $this->customer] as $user) {
            $this->as($user)
                ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->otherAgent->id])
                ->assertForbidden();
        }

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_finished_tickets_cannot_be_assigned(): void
    {
        foreach ([TicketStatus::Resolved, TicketStatus::Closed] as $status) {
            $ticket = $this->ticketFor($this->customer, ['status' => $status]);

            $this->as($this->admin)
                ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->agent->id])
                ->assertStatus(409)
                ->assertJsonPath('success', false);

            $this->assertNull($ticket->fresh()->assigned_to);
        }
    }

    public function test_an_assigned_agent_can_then_work_the_ticket(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertForbidden();

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->agent->id])
            ->assertOk();

        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertOk();
    }

    // ---- agent accepts ------------------------------------------------------

    public function test_agent_can_accept_an_unassigned_ticket(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->agent)
            ->postJson("/api/tickets/{$ticket->number}/accept")
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $this->agent->id)
            ->assertJsonPath('message', 'Ticket accepted');

        $this->assertSame($this->agent->id, $ticket->fresh()->assigned_to);

        Event::assertDispatched(
            TicketAssigned::class,
            fn ($e) => $e->method === AssignmentMethod::Accepted && $e->actor->is($this->agent)
        );
    }

    public function test_second_agent_accepting_the_same_ticket_gets_409_and_does_not_take_it(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->agent)->postJson("/api/tickets/{$ticket->number}/accept")->assertOk();

        $this->as($this->otherAgent)
            ->postJson("/api/tickets/{$ticket->number}/accept")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This ticket has already been assigned to another agent.');

        $this->assertSame($this->agent->id, $ticket->fresh()->assigned_to);
    }

    public function test_the_loser_of_a_race_is_decided_by_the_locked_row_not_a_stale_copy(): void
    {
        // Both agents load the ticket while it is still unassigned: this is the "same moment" case.
        $agentAsSeen = $this->ticketFor($this->customer);
        $otherAgentAsSeen = $agentAsSeen->replicate()->setRawAttributes($agentAsSeen->getAttributes());
        $otherAgentAsSeen->exists = true;

        $service = app(TicketAssignmentService::class);

        $service->accept($agentAsSeen, $this->agent); // the winner commits first

        // The second request still holds a copy saying "assigned_to = null".
        $this->assertNull($otherAgentAsSeen->assigned_to);

        try {
            $service->accept($otherAgentAsSeen, $this->otherAgent);
            $this->fail('The second agent must not be able to take an already assigned ticket.');
        } catch (TicketAssignmentException $e) {
            $this->assertSame('This ticket has already been assigned to another agent.', $e->getMessage());
        }

        $this->assertSame($this->agent->id, $agentAsSeen->fresh()->assigned_to);
    }

    public function test_accepting_a_ticket_twice_is_harmless(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->agent)->postJson("/api/tickets/{$ticket->number}/accept")->assertOk();
        $this->as($this->agent)->postJson("/api/tickets/{$ticket->number}/accept")->assertOk();

        Event::assertDispatchedTimes(TicketAssigned::class, 1);
    }

    public function test_an_unavailable_agent_can_still_accept_a_ticket_themselves(): void
    {
        $away = User::factory()->agent()->unavailable()->create();
        $ticket = $this->ticketFor($this->customer);

        // "Available" only controls automatic assignment; choosing a ticket yourself is fine.
        $this->as($away)->postJson("/api/tickets/{$ticket->number}/accept")->assertOk();
    }

    public function test_only_agents_can_accept(): void
    {
        $ticket = $this->ticketFor($this->customer);

        foreach ([$this->admin, $this->customer, $this->otherCustomer] as $user) {
            $this->as($user)->postJson("/api/tickets/{$ticket->number}/accept")->assertForbidden();
        }

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_finished_tickets_cannot_be_accepted(): void
    {
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed]);

        $this->as($this->agent)
            ->postJson("/api/tickets/{$ticket->number}/accept")
            ->assertStatus(409);
    }

    public function test_guests_cannot_assign_or_accept(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->postJson("/api/tickets/{$ticket->number}/accept")->assertUnauthorized();
        $this->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->agent->id])->assertUnauthorized();
    }
}
