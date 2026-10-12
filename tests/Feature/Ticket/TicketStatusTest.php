<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;
use App\Events\Ticket\TicketStatusChanged;
use App\Events\Ticket\TicketUpdated;
use Illuminate\Support\Facades\Event;

class TicketStatusTest extends TicketTestCase
{
    public function test_resolving_sets_resolved_at_and_fires_an_event(): void
    {
        Event::fake([TicketStatusChanged::class]);
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::InProgress, 'assigned_to' => $this->agent->id]);

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$ticket->number}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'resolved')
            ->assertJsonPath('message', 'Ticket status changed to Resolved');

        $this->assertNotNull($ticket->fresh()->resolved_at);

        Event::assertDispatched(
            TicketStatusChanged::class,
            fn ($e) => $e->from === TicketStatus::InProgress && $e->to === TicketStatus::Resolved && $e->actor->is($this->agent)
        );
    }

    public function test_closing_sets_closed_at(): void
    {
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'assigned_to' => $this->agent->id]);

        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/close")->assertOk();

        $this->assertNotNull($ticket->fresh()->closed_at);
    }

    public function test_resolved_ticket_can_be_reopened_and_resolved_at_is_cleared(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id, 'status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'in_progress')
            ->assertJsonPath('data.resolved_at', null);
    }

    public function test_closed_is_final(): void
    {
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'assigned_to' => $this->agent->id]);

        $this->as($this->admin)
            ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => 'open'])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A ticket cannot move from "Closed" to "Open".');

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_invalid_transitions_are_rejected_with_409(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        // open -> open is not a move
        $this->as($this->agent)
            ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => 'open'])
            ->assertStatus(409);

        // resolve twice
        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertOk();
        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertStatus(409);
    }

    public function test_every_allowed_transition_works_and_nothing_else(): void
    {
        foreach (TicketStatus::cases() as $from) {
            foreach (TicketStatus::cases() as $to) {
                $ticket = $this->ticketFor($this->customer, ['status' => $from, 'assigned_to' => $this->agent->id]);

                $response = $this->as($this->admin)
                    ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => $to->value]);

                $from->canTransitionTo($to)
                    ? $response->assertOk()
                    : $response->assertStatus(409);
            }
        }
    }

    public function test_status_value_is_validated(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->admin)
            ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => 'banana'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_update_event_carries_old_and_new_values(): void
    {
        Event::fake([TicketUpdated::class]);
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id, 'priority' => 'medium']);

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$ticket->number}", ['priority' => 'high', 'subject' => $ticket->subject])
            ->assertOk();

        Event::assertDispatched(
            TicketUpdated::class,
            fn ($e) => $e->changes === ['priority' => ['from' => 'medium', 'to' => 'high']]
        );
    }

    public function test_update_without_real_changes_fires_no_event(): void
    {
        Event::fake([TicketUpdated::class]);
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$ticket->number}", ['subject' => $ticket->subject])
            ->assertOk();

        Event::assertNotDispatched(TicketUpdated::class);
    }
}
