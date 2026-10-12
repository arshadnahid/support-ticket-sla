<?php

namespace Tests\Feature\Ticket;

use App\Enums\SlaState;
use App\Enums\TicketStatus;
use App\Events\Ticket\TicketSlaEscalated;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Notifications\TicketSlaEscalatedNotification;
use App\Services\Sla\SlaEscalationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

class TicketSlaEscalationTest extends TicketTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A ticket whose warning time is $warnMinutes from now and deadline $dueMinutes from now
     * (negative = already passed).
     */
    private function slaTicket(int $warnMinutes, int $dueMinutes, array $attributes = []): Ticket
    {
        return $this->ticketFor($this->customer, $attributes + [
            'assigned_to' => $this->agent->id,
            'sla_warning_at' => now()->addMinutes($warnMinutes),
            'due_at' => now()->addMinutes($dueMinutes),
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('tickets:escalate-sla')->assertSuccessful();
    }

    public function test_a_ticket_past_its_warning_time_is_escalated_as_approaching(): void
    {
        Notification::fake();
        $ticket = $this->slaTicket(warnMinutes: -5, dueMinutes: 30);

        $this->sweep();

        $ticket->refresh();
        $this->assertSame(1, $ticket->escalation_level);
        $this->assertNotNull($ticket->escalated_at);
    }

    public function test_a_ticket_past_its_deadline_is_escalated_as_breached(): void
    {
        Notification::fake();
        $ticket = $this->slaTicket(warnMinutes: -60, dueMinutes: -1);

        $this->sweep();

        $this->assertSame(2, $ticket->fresh()->escalation_level);
    }

    public function test_a_ticket_within_sla_is_left_alone(): void
    {
        Notification::fake();
        $ticket = $this->slaTicket(warnMinutes: 30, dueMinutes: 60);
        $noDeadline = $this->ticketFor($this->customer);

        $this->sweep();

        $this->assertSame(0, $ticket->fresh()->escalation_level);
        $this->assertSame(0, $noDeadline->fresh()->escalation_level);
        $this->assertDatabaseCount('ticket_activities', 0);
        Notification::assertNothingSent();
    }

    public function test_finished_tickets_are_not_escalated(): void
    {
        Notification::fake();
        $this->slaTicket(-60, -30, ['status' => TicketStatus::Resolved, 'resolved_at' => now()->subHour()]);
        $this->slaTicket(-60, -30, ['status' => TicketStatus::Closed, 'closed_at' => now()->subHour()]);

        $this->sweep();

        $this->assertDatabaseCount('ticket_activities', 0);
        Notification::assertNothingSent();
    }

    public function test_escalation_writes_a_history_entry(): void
    {
        Notification::fake();
        $ticket = $this->slaTicket(-60, -1);

        $this->sweep();

        $activity = TicketActivity::first();
        $this->assertSame($ticket->id, $activity->ticket_id);
        $this->assertNull($activity->user_id, 'done by the system');
        $this->assertSame(TicketActivity::SLA_BREACHED, $activity->type);
        $this->assertSame('breached', $activity->properties['state']);
        $this->assertSame($this->agent->id, $activity->properties['assigned_to']);
    }

    public function test_each_level_is_escalated_once_even_if_the_sweep_runs_again(): void
    {
        Notification::fake();
        $this->slaTicket(-5, 30);

        $this->sweep();
        $this->sweep();
        $this->sweep();

        $this->assertDatabaseCount('ticket_activities', 1);
        Notification::assertSentTimes(TicketSlaEscalatedNotification::class, 1);
    }

    public function test_a_ticket_is_escalated_again_when_it_later_breaches(): void
    {
        Notification::fake();
        $ticket = $this->slaTicket(-5, 30);

        $this->sweep();
        $this->assertSame(1, $ticket->fresh()->escalation_level);

        Carbon::setTestNow(now()->addMinutes(31)); // past the deadline now
        $this->sweep();
        $this->sweep();

        $this->assertSame(2, $ticket->fresh()->escalation_level);
        $this->assertSame(
            [TicketActivity::SLA_WARNING, TicketActivity::SLA_BREACHED],
            TicketActivity::orderBy('id')->pluck('type')->all(),
        );
    }

    public function test_a_ticket_that_breaches_without_ever_being_warned_gets_one_breach_escalation(): void
    {
        Notification::fake();
        $this->slaTicket(-120, -1);

        $this->sweep();
        $this->sweep();

        $this->assertSame([TicketActivity::SLA_BREACHED], TicketActivity::pluck('type')->all());
    }

    public function test_escalation_fires_an_event_per_level(): void
    {
        Event::fake([TicketSlaEscalated::class]);
        $ticket = $this->slaTicket(-5, 30);

        app(SlaEscalationService::class)->escalate($ticket->id);
        app(SlaEscalationService::class)->escalate($ticket->id);

        Event::assertDispatchedTimes(TicketSlaEscalated::class, 1);
        Event::assertDispatched(TicketSlaEscalated::class, fn ($e) => $e->state === SlaState::Approaching && $e->ticket->is($ticket));
    }

    // ---- Who is notified ----------------------------------------------------------------

    public function test_warning_goes_to_the_assigned_agent_only(): void
    {
        Notification::fake();
        $this->slaTicket(-5, 30);

        $this->sweep();

        Notification::assertSentTo($this->agent, TicketSlaEscalatedNotification::class, fn ($n) => $n->state === SlaState::Approaching);
        Notification::assertNotSentTo($this->admin, TicketSlaEscalatedNotification::class);
        Notification::assertNotSentTo($this->otherAgent, TicketSlaEscalatedNotification::class);
    }

    public function test_breach_goes_to_the_assigned_agent_and_all_admins(): void
    {
        Notification::fake();
        $secondAdmin = User::factory()->admin()->create();
        $this->slaTicket(-60, -1);

        $this->sweep();

        foreach ([$this->agent, $this->admin, $secondAdmin] as $user) {
            Notification::assertSentTo($user, TicketSlaEscalatedNotification::class, fn ($n) => $n->state === SlaState::Breached);
        }
    }

    public function test_an_unassigned_ticket_is_escalated_to_admins(): void
    {
        Notification::fake();
        $this->slaTicket(-5, 30, ['assigned_to' => null]);

        $this->sweep();

        Notification::assertSentTo($this->admin, TicketSlaEscalatedNotification::class);
        Notification::assertNotSentTo($this->agent, TicketSlaEscalatedNotification::class);
    }

    public function test_customers_and_inactive_users_are_never_notified(): void
    {
        Notification::fake();
        $this->agent->update(['is_active' => false]);
        $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
        $this->slaTicket(-60, -1);

        $this->sweep();

        Notification::assertNotSentTo($this->customer, TicketSlaEscalatedNotification::class);
        Notification::assertNotSentTo($inactiveAdmin, TicketSlaEscalatedNotification::class);
        Notification::assertNotSentTo($this->agent, TicketSlaEscalatedNotification::class);
        Notification::assertSentTo($this->admin, TicketSlaEscalatedNotification::class);
    }

    public function test_the_notification_has_an_email_and_a_stored_copy(): void
    {
        $ticket = $this->slaTicket(-60, -1, ['subject' => 'Printer on fire']);

        $this->sweep();

        $stored = $this->agent->notifications()->first();
        $this->assertNotNull($stored, 'stored in the notifications table');
        $this->assertSame($ticket->number, $stored->data['ticket_number']);
        $this->assertSame('sla_breached', $stored->data['type']);

        $mail = (new TicketSlaEscalatedNotification($ticket->fresh(), SlaState::Breached))->toMail($this->agent);
        $this->assertSame('SLA breached: '.$ticket->number, $mail->subject);
        $this->assertContains('Subject: Printer on fire', $mail->introLines);
    }

    // ---- Recalculation ------------------------------------------------------------------

    public function test_recalculate_applies_new_rules_to_open_tickets_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 04:00:00', 'UTC')); // Monday 10:00 Dhaka
        $open = $this->ticketFor($this->customer, ['priority' => 'critical', 'due_at' => null]);
        $closedDue = now()->addYear()->startOfSecond();
        $closed = $this->ticketFor($this->customer, ['priority' => 'critical', 'status' => TicketStatus::Closed, 'due_at' => $closedDue]);

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertSame('2026-10-12 06:00', $open->fresh()->due_at->utc()->format('Y-m-d H:i'));
        $this->assertSame($closedDue->getTimestamp(), $closed->fresh()->due_at->getTimestamp());
    }

    public function test_recalculate_lowers_the_escalation_level_when_the_deadline_moves_away(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 04:00:00', 'UTC'));
        $ticket = $this->ticketFor($this->customer, [
            'priority' => 'critical',
            'created_at' => now()->subHours(1),
            'due_at' => now()->subMinute(),
            'sla_warning_at' => now()->subMinutes(30),
            'escalation_level' => 2,
        ]);

        config(['sla.resolution_hours.critical' => 48]); // rules relaxed

        $this->artisan('tickets:recalculate-sla')->assertSuccessful();

        $this->assertSame(0, $ticket->fresh()->escalation_level);
    }
}
