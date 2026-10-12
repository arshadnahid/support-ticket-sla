<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketReplyNotification;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Who is told about ticket events: created, assigned, agent/customer replied, resolved.
 * (SLA approaching/breached are covered in TicketSlaEscalationTest.)
 */
class TicketNotificationsTest extends TicketTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // Automatic assignment would add assignment notifications; these tests drive it by hand.
        config(['tickets.assignment.auto_assign' => false]);
    }

    public function test_creating_a_ticket_confirms_to_the_customer_and_alerts_admins(): void
    {
        $this->as($this->customer)->postJson('/api/tickets', $this->validPayload())->assertCreated();

        Notification::assertSentTo($this->customer, TicketCreatedNotification::class);
        Notification::assertSentTo($this->admin, TicketCreatedNotification::class);
        Notification::assertNotSentTo($this->agent, TicketCreatedNotification::class);
    }

    public function test_assigning_tells_the_agent_and_the_customer(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->admin)
            ->postJson("/api/tickets/{$ticket->number}/assign", ['agent_id' => $this->agent->id])
            ->assertOk();

        Notification::assertSentTo($this->agent, TicketAssignedNotification::class);
        Notification::assertSentTo($this->customer, TicketAssignedNotification::class);
    }

    public function test_an_agent_accepting_a_ticket_is_not_told_about_their_own_action(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->agent)->postJson("/api/tickets/{$ticket->number}/accept")->assertOk();

        Notification::assertNotSentTo($this->agent, TicketAssignedNotification::class);
        Notification::assertSentTo($this->customer, TicketAssignedNotification::class);
    }

    public function test_an_agent_reply_notifies_the_customer(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->agent)
            ->postJson("/api/tickets/{$ticket->number}/replies", ['body' => 'Try resetting your password.'])
            ->assertCreated();

        Notification::assertSentTo($this->customer, TicketReplyNotification::class);
        Notification::assertNotSentTo($this->agent, TicketReplyNotification::class);
    }

    public function test_a_customer_reply_notifies_the_assigned_agent(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->customer)
            ->postJson("/api/tickets/{$ticket->number}/replies", ['body' => 'It still fails.'])
            ->assertCreated();

        Notification::assertSentTo($this->agent, TicketReplyNotification::class);
        Notification::assertNotSentTo($this->admin, TicketReplyNotification::class);
        Notification::assertNotSentTo($this->customer, TicketReplyNotification::class);
    }

    public function test_a_customer_reply_on_an_unassigned_ticket_notifies_admins(): void
    {
        $ticket = $this->ticketFor($this->customer);

        $this->as($this->customer)
            ->postJson("/api/tickets/{$ticket->number}/replies", ['body' => 'Anyone there?'])
            ->assertCreated();

        Notification::assertSentTo($this->admin, TicketReplyNotification::class);
        Notification::assertNotSentTo($this->agent, TicketReplyNotification::class);
    }

    public function test_internal_notes_are_never_announced(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->agent)
            ->postJson("/api/tickets/{$ticket->number}/replies", ['body' => 'Looks like a bug.', 'is_internal' => true])
            ->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_resolving_notifies_the_customer(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertOk();

        Notification::assertSentTo($this->customer, TicketResolvedNotification::class);
    }

    public function test_other_status_changes_are_not_announced(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->agent)
            ->patchJson("/api/tickets/{$ticket->number}/status", ['status' => TicketStatus::InProgress->value])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_inactive_users_are_not_notified(): void
    {
        $this->customer->update(['is_active' => false]);
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->agent)->patchJson("/api/tickets/{$ticket->number}/resolve")->assertOk();

        Notification::assertNothingSent();
    }

    public function test_the_notification_has_an_email_and_a_stored_copy(): void
    {
        $ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
        $notification = new TicketResolvedNotification($ticket);

        $this->assertSame(['mail', 'database'], $notification->via($this->customer));
        $this->assertStringContainsString($ticket->number, $notification->toMail($this->customer)->subject);
        $this->assertSame('ticket_resolved', $notification->toArray($this->customer)['type']);
    }
}
