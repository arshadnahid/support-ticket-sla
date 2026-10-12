<?php

namespace Tests\Feature\Ticket;

use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sla\SlaService;
use Illuminate\Support\Carbon;

/**
 * Default rules in these tests: Critical 2 h, High 8 h, Medium 24 h, Low 48 h of business time,
 * Sunday to Thursday 09:00 to 18:00 in Asia/Dhaka (UTC+6), warning at 80%.
 * Thu 2026-10-15 11:00 UTC is Thursday 17:00 in Dhaka.
 */
class TicketSlaTest extends TicketTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function create(string $priority, ?string $at = null): array
    {
        if ($at) {
            Carbon::setTestNow(Carbon::parse($at, 'UTC'));
        }

        return $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload(['priority' => $priority]))
            ->assertCreated()
            ->json('data');
    }

    // ---- Deadlines ----------------------------------------------------------------------

    public function test_critical_ticket_created_thursday_5pm_is_due_sunday_10am_not_7pm(): void
    {
        $ticket = $this->create('critical', '2026-10-15 11:00:00'); // Thursday 17:00 Dhaka

        // Sunday 10:00 Dhaka = 04:00 UTC. Plain "+2 hours" would give Thursday 19:00 (after closing).
        $this->assertSame('2026-10-18 04:00:00', Carbon::parse($ticket['due_at'])->utc()->format('Y-m-d H:i:s'));
    }

    public function test_each_priority_gets_its_own_deadline_from_config(): void
    {
        // Monday 2026-10-12 10:00 Dhaka = 04:00 UTC.
        $critical = $this->create('critical', '2026-10-12 04:00:00');
        $this->assertSame('2026-10-12 06:00', Carbon::parse($critical['due_at'])->utc()->format('Y-m-d H:i')); // +2 h

        // High: 8 working hours from 10:00 is exactly closing time, Monday 18:00 Dhaka = 12:00 UTC.
        $high = $this->create('high');
        $this->assertSame('2026-10-12 12:00', Carbon::parse($high['due_at'])->utc()->format('Y-m-d H:i'));

        $low = $this->create('low');
        $medium = $this->create('medium');

        $this->assertTrue(Carbon::parse($critical['due_at'])->lt($high['due_at']));
        $this->assertTrue(Carbon::parse($high['due_at'])->lt($medium['due_at']));
        $this->assertTrue(Carbon::parse($medium['due_at'])->lt($low['due_at']));
    }

    public function test_changing_the_sla_rules_in_config_changes_deadlines_without_code_changes(): void
    {
        config(['sla.resolution_hours.critical' => 1]);

        $ticket = $this->create('critical', '2026-10-12 04:00:00'); // Monday 10:00 Dhaka

        $this->assertSame('2026-10-12 05:00', Carbon::parse($ticket['due_at'])->utc()->format('Y-m-d H:i'));
    }

    public function test_business_days_and_hours_are_configurable(): void
    {
        // Monday to Friday, 08:00 to 12:00, UTC. Friday 11:00 + 2 h = Monday 09:00.
        config(['sla.business_hours' => ['timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '12:00', 'holidays' => []]]);

        $ticket = $this->create('critical', '2026-10-16 11:00:00');

        $this->assertSame('2026-10-19 09:00', Carbon::parse($ticket['due_at'])->utc()->format('Y-m-d H:i'));
    }

    public function test_warning_time_is_80_percent_of_the_sla_in_working_time(): void
    {
        $ticket = $this->create('critical', '2026-10-15 11:00:00'); // Thursday 17:00 Dhaka

        // 80% of 120 min = 96 min: 60 left on Thursday, 36 on Sunday = Sunday 09:36 Dhaka = 03:36 UTC.
        $warningAt = Ticket::whereNumber($ticket['number'])->first()->sla_warning_at;

        $this->assertSame('2026-10-18 03:36', $warningAt->utc()->format('Y-m-d H:i'));
    }

    // ---- States -------------------------------------------------------------------------

    public function test_a_ticket_is_within_then_approaching_then_breached(): void
    {
        $this->create('critical', '2026-10-12 04:00:00'); // Monday 10:00 Dhaka, due 12:00, warning 11:36
        $ticket = Ticket::first();
        $sla = app(SlaService::class);

        Carbon::setTestNow(Carbon::parse('2026-10-12 05:00:00', 'UTC')); // 11:00 Dhaka
        $this->assertSame(SlaState::Within, $sla->stateOf($ticket));

        Carbon::setTestNow(Carbon::parse('2026-10-12 05:36:00', 'UTC')); // 11:36 Dhaka: warning time reached
        $this->assertSame(SlaState::Approaching, $sla->stateOf($ticket));

        Carbon::setTestNow(Carbon::parse('2026-10-12 06:00:00', 'UTC')); // exactly the deadline: not yet breached
        $this->assertSame(SlaState::Approaching, $sla->stateOf($ticket));

        Carbon::setTestNow(Carbon::parse('2026-10-12 06:00:01', 'UTC'));
        $this->assertSame(SlaState::Breached, $sla->stateOf($ticket));
    }

    public function test_the_clock_does_not_run_outside_business_hours(): void
    {
        // Thursday 17:00 Dhaka, due Sunday 10:00. On Friday and Saturday the ticket is still fine.
        $this->create('critical', '2026-10-15 11:00:00');
        $ticket = Ticket::first();

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', 'UTC')); // Saturday
        $this->assertSame(SlaState::Within, app(SlaService::class)->stateOf($ticket));
    }

    public function test_finished_tickets_are_judged_by_when_they_were_finished(): void
    {
        $sla = app(SlaService::class);
        $due = now()->addHours(2);

        $onTime = $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'due_at' => $due, 'sla_warning_at' => $due, 'resolved_at' => $due->copy()->subMinute()]);
        $late = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'due_at' => $due, 'sla_warning_at' => $due, 'closed_at' => $due->copy()->addMinute()]);

        Carbon::setTestNow($due->copy()->addDays(5)); // long after: irrelevant for finished tickets

        $this->assertSame(SlaState::Within, $sla->stateOf($onTime));
        $this->assertSame(SlaState::Breached, $sla->stateOf($late));
    }

    public function test_a_ticket_without_a_deadline_has_no_state(): void
    {
        $this->assertNull(app(SlaService::class)->stateOf($this->ticketFor($this->customer)));
    }

    public function test_remaining_time_is_counted_in_working_minutes(): void
    {
        $this->create('critical', '2026-10-15 11:00:00'); // Thursday 17:00 Dhaka, due Sunday 10:00

        // Saturday: the 60 minutes of Thursday are used up; Friday and Saturday add nothing, so the
        // last hour (Sunday 09:00 to 10:00) is all that is left.
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00', 'UTC'));
        $this->assertSame(60.0, app(SlaService::class)->minutesRemaining(Ticket::first()));
    }

    // ---- API ----------------------------------------------------------------------------

    public function test_staff_see_the_sla_block_and_customers_do_not(): void
    {
        $this->create('critical', '2026-10-12 04:00:00');
        $number = Ticket::first()->number;
        Carbon::setTestNow(Carbon::parse('2026-10-12 05:40:00', 'UTC')); // approaching

        $this->as($this->admin)->getJson("/api/tickets/$number")
            ->assertJsonPath('data.sla.state.value', 'approaching')
            ->assertJsonPath('data.sla.state.label', 'Approaching SLA breach')
            ->assertJsonPath('data.sla.business_minutes_remaining', 20)
            ->assertJsonPath('data.sla.escalation_level', 0);

        $this->as($this->customer)->getJson("/api/tickets/$number")
            ->assertOk()
            ->assertJsonMissingPath('data.sla')
            ->assertJsonStructure(['data' => ['due_at']]);
    }

    public function test_list_can_be_filtered_by_sla_state(): void
    {
        $now = now();
        $within = $this->ticketFor($this->customer, ['due_at' => $now->copy()->addHours(5), 'sla_warning_at' => $now->copy()->addHours(4)]);
        $approaching = $this->ticketFor($this->customer, ['due_at' => $now->copy()->addHour(), 'sla_warning_at' => $now->copy()->subMinute()]);
        $breached = $this->ticketFor($this->customer, ['due_at' => $now->copy()->subMinute(), 'sla_warning_at' => $now->copy()->subHour()]);
        $this->ticketFor($this->customer); // no deadline: matches no state
        $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'due_at' => $now->copy()->subDay(), 'sla_warning_at' => $now->copy()->subDays(2)]);

        $numbers = fn (string $sla) => $this->as($this->admin)->getJson("/api/tickets?sla=$sla")->json('data.*.number');

        $this->assertSame([$within->number], $numbers('within'));
        $this->assertSame([$approaching->number], $numbers('approaching'));
        $this->assertSame([$breached->number], $numbers('breached'), 'closed tickets are not listed as breached');

        $this->as($this->admin)->getJson('/api/tickets?sla=nonsense')->assertUnprocessable()->assertJsonValidationErrors('sla');
    }

    public function test_changing_priority_recalculates_the_deadline_from_creation_time(): void
    {
        $ticket = $this->create('low', '2026-10-12 04:00:00'); // Monday 10:00 Dhaka
        $lowDue = Carbon::parse($ticket['due_at']);

        $this->as($this->admin)
            ->patchJson('/api/tickets/'.$ticket['number'], ['priority' => 'critical'])
            ->assertOk();

        $fresh = Ticket::whereNumber($ticket['number'])->first();

        $this->assertSame('2026-10-12 06:00', $fresh->due_at->utc()->format('Y-m-d H:i')); // +2 working hours
        $this->assertTrue($fresh->due_at->lt($lowDue));
    }

    public function test_changing_priority_of_a_finished_ticket_keeps_its_deadline(): void
    {
        $due = now()->addDay()->startOfSecond();
        $ticket = $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'due_at' => $due, 'assigned_to' => $this->agent->id]);

        $this->as($this->admin)->patchJson('/api/tickets/'.$ticket->number, ['priority' => TicketPriority::Critical->value])->assertOk();

        $this->assertSame($due->getTimestamp(), $ticket->fresh()->due_at->getTimestamp());
    }
}
