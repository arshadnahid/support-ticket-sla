<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Support\Carbon;

class TicketSearchTest extends TicketTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function numbers(string $query, $as = null): array
    {
        return $this->as($as ?? $this->admin)->getJson('/api/tickets?'.$query)->assertOk()->json('data.*.number');
    }

    // ---- Keyword and ticket number ------------------------------------------------------

    public function test_keyword_matches_subject_description_and_number(): void
    {
        $bySubject = $this->ticketFor($this->customer, ['subject' => 'Cannot reset my password', 'description' => 'x']);
        $byDescription = $this->ticketFor($this->customer, ['subject' => 'Billing', 'description' => 'The invoice shows the wrong PASSWORD policy']);
        $other = $this->ticketFor($this->customer, ['subject' => 'Printer', 'description' => 'Out of toner']);

        $this->assertEqualsCanonicalizing([$bySubject->number, $byDescription->number], $this->numbers('q=password'));
        $this->assertSame([$other->number], $this->numbers('q=toner'));
        $this->assertSame([$other->number], $this->numbers('q='.substr($other->number, -6)), 'part of the ticket number');
    }

    public function test_keyword_wildcards_are_searched_literally(): void
    {
        $percent = $this->ticketFor($this->customer, ['subject' => 'Discount of 50% applied']);
        $this->ticketFor($this->customer, ['subject' => 'Discount of 500 applied']);
        $underscore = $this->ticketFor($this->customer, ['subject' => 'error_code 7']);
        $this->ticketFor($this->customer, ['subject' => 'errorXcode 7']);

        $this->assertSame([$percent->number], $this->numbers('q='.urlencode('50%')));
        $this->assertSame([$underscore->number], $this->numbers('q=error_code'));
        $this->assertSame([], $this->as($this->admin)->getJson('/api/tickets?q='.urlencode('%%'))->json('data'));
    }

    public function test_keyword_is_validated(): void
    {
        $this->as($this->admin)->getJson('/api/tickets?q=a')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->as($this->admin)->getJson('/api/tickets?q='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_exact_ticket_number_lookup_ignores_case(): void
    {
        $wanted = $this->ticketFor($this->customer);
        $this->ticketFor($this->customer);

        $this->assertSame([$wanted->number], $this->numbers('number='.strtolower($wanted->number)));
        $this->as($this->admin)->getJson('/api/tickets?number=TKT-1999-000001')->assertNotFound();
    }

    // ---- Single filters -----------------------------------------------------------------

    public function test_filter_by_status_and_priority(): void
    {
        $a = $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'priority' => TicketPriority::High]);
        $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'priority' => TicketPriority::Low]);
        $this->ticketFor($this->customer, ['status' => TicketStatus::Open, 'priority' => TicketPriority::High]);

        $this->assertSame([$a->number], $this->numbers('status=resolved&priority=high'));
        $this->assertCount(2, $this->numbers('priority=high'));
        $this->as($this->admin)->getJson('/api/tickets?priority=urgent')->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    public function test_filter_by_category(): void
    {
        $other = Category::factory()->create();
        $mine = Ticket::factory()->createdBy($this->customer)->create(['category_id' => $other->id]);
        $this->ticketFor($this->customer);

        $this->assertSame([$mine->number], $this->numbers('category_id='.$other->id));
        $this->as($this->admin)->getJson('/api/tickets?category_id=99999')->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_filter_by_assigned_agent_and_unassigned(): void
    {
        $toAgent = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
        $toOther = $this->ticketFor($this->customer, ['assigned_to' => $this->otherAgent->id]);
        $free = $this->ticketFor($this->customer);

        $this->assertSame([$toAgent->number], $this->numbers('assigned_to='.$this->agent->id));
        $this->assertSame([$toOther->number], $this->numbers('assigned_to='.$this->otherAgent->id));
        $this->assertSame([$free->number], $this->numbers('unassigned=true'));
        $this->assertSame([$free->number], $this->numbers('unassigned=1'));

        $this->as($this->admin)->getJson('/api/tickets?assigned_to='.$this->agent->id.'&unassigned=true')
            ->assertUnprocessable();
        $this->as($this->admin)->getJson('/api/tickets?unassigned=maybe')->assertUnprocessable()->assertJsonValidationErrors('unassigned');
    }

    public function test_filter_by_customer(): void
    {
        $mine = $this->ticketFor($this->customer);
        $theirs = $this->ticketFor($this->otherCustomer);

        $this->assertSame([$mine->number], $this->numbers('customer_id='.$this->customer->id));
        $this->assertSame([$theirs->number], $this->numbers('customer_id='.$this->otherCustomer->id));
    }

    public function test_filter_by_created_date_is_inclusive_of_whole_days(): void
    {
        $early = $this->ticketFor($this->customer, ['created_at' => '2026-10-01 00:00:00']);
        $lateOnLastDay = $this->ticketFor($this->customer, ['created_at' => '2026-10-10 23:59:59']);
        $after = $this->ticketFor($this->customer, ['created_at' => '2026-10-11 00:00:00']);

        $this->assertEqualsCanonicalizing([$early->number, $lateOnLastDay->number], $this->numbers('created_from=2026-10-01&created_to=2026-10-10'));
        $this->assertSame([$after->number], $this->numbers('created_from=2026-10-11'));
        $this->assertEqualsCanonicalizing([$early->number], $this->numbers('created_to=2026-10-01'));

        $this->as($this->admin)->getJson('/api/tickets?created_from=10/01/2026')->assertUnprocessable()->assertJsonValidationErrors('created_from');
        $this->as($this->admin)->getJson('/api/tickets?created_from=2026-10-10&created_to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('created_to');
    }

    public function test_filter_by_sla_state_still_works_with_the_other_filters(): void
    {
        $breachedHigh = $this->ticketFor($this->customer, ['priority' => TicketPriority::High, 'due_at' => now()->subHour(), 'sla_warning_at' => now()->subHours(2)]);
        $this->ticketFor($this->customer, ['priority' => TicketPriority::Low, 'due_at' => now()->subHour(), 'sla_warning_at' => now()->subHours(2)]);

        $this->assertSame([$breachedHigh->number], $this->numbers('sla=breached&priority=high'));
    }

    // ---- Combining ----------------------------------------------------------------------

    public function test_filters_combine_with_and(): void
    {
        $match = $this->ticketFor($this->customer, ['subject' => 'VPN down', 'priority' => TicketPriority::Critical, 'assigned_to' => $this->agent->id, 'status' => TicketStatus::InProgress]);
        $this->ticketFor($this->customer, ['subject' => 'VPN slow', 'priority' => TicketPriority::Low, 'assigned_to' => $this->agent->id, 'status' => TicketStatus::InProgress]);
        $this->ticketFor($this->customer, ['subject' => 'VPN down', 'priority' => TicketPriority::Critical, 'assigned_to' => $this->otherAgent->id, 'status' => TicketStatus::InProgress]);
        $this->ticketFor($this->customer, ['subject' => 'Mouse', 'priority' => TicketPriority::Critical, 'assigned_to' => $this->agent->id, 'status' => TicketStatus::InProgress]);

        $this->assertSame(
            [$match->number],
            $this->numbers('q=vpn&priority=critical&assigned_to='.$this->agent->id.'&status=in_progress&customer_id='.$this->customer->id),
        );
    }

    public function test_search_works_on_the_active_and_resolved_lists_and_with_sorting(): void
    {
        $open = $this->ticketFor($this->customer, ['subject' => 'Login broken']);
        $done = $this->ticketFor($this->customer, ['subject' => 'Login fixed', 'status' => TicketStatus::Resolved, 'assigned_to' => $this->agent->id]);
        $this->ticketFor($this->customer, ['subject' => 'Printer']);

        $this->assertSame([$open->number], $this->as($this->admin)->getJson('/api/tickets/active?q=login')->json('data.*.number'));
        $this->assertSame([$done->number], $this->as($this->admin)->getJson('/api/tickets/resolved?q=login')->json('data.*.number'));

        $old = $this->ticketFor($this->customer, ['subject' => 'Login old', 'created_at' => now()->subDays(3)]);
        $this->assertSame([$old->number, $open->number, $done->number], $this->numbers('q=login&sort=created_asc'));
    }

    // ---- Access rules are never widened -------------------------------------------------

    public function test_filters_cannot_reveal_tickets_the_user_may_not_see(): void
    {
        $theirs = $this->ticketFor($this->otherCustomer, ['subject' => 'Secret project']);
        $assignedElsewhere = $this->ticketFor($this->customer, ['subject' => 'Secret project', 'assigned_to' => $this->otherAgent->id]);
        $mine = $this->ticketFor($this->customer, ['subject' => 'Secret project']);

        // A customer asking for someone else's tickets (by customer, number, keyword) gets nothing.
        $this->as($this->customer)->getJson('/api/tickets?customer_id='.$this->otherCustomer->id)->assertNotFound();
        $this->as($this->customer)->getJson('/api/tickets?number='.$theirs->number)->assertNotFound();
        $this->assertEqualsCanonicalizing([$mine->number, $assignedElsewhere->number], $this->numbers('q=secret', $this->customer));

        // An agent does not see tickets assigned to another agent, even when filtering for that agent.
        // (They do see the unassigned queue, which is why both unassigned tickets match here.)
        $this->as($this->agent)->getJson('/api/tickets?assigned_to='.$this->otherAgent->id)->assertNotFound();
        $this->assertEqualsCanonicalizing([$mine->number, $theirs->number], $this->numbers('q=secret', $this->agent));
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        foreach (range(1, 3) as $i) {
            $this->ticketFor($this->customer, ['subject' => "Refund $i", 'priority' => TicketPriority::High]);
        }

        $url = $this->as($this->admin)->getJson('/api/tickets?q=refund&priority=high&per_page=2')->assertOk()->json('pagination.next_page_url');

        $this->assertStringContainsString('q=refund', $url);
        $this->assertStringContainsString('priority=high', $url);
        $this->assertStringContainsString('per_page=2', $url);
    }

    public function test_search_does_not_run_extra_queries_per_ticket(): void
    {
        $count = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->as($this->admin)->getJson('/api/tickets?q=ticket&priority=high')->assertOk();

            return count(\DB::getQueryLog());
        };

        Ticket::factory()->count(2)->assignedTo($this->agent)->create(['category_id' => $this->category->id, 'subject' => 'a ticket', 'priority' => TicketPriority::High]);
        $withTwo = $count();
        Ticket::factory()->count(10)->assignedTo($this->agent)->create(['category_id' => $this->category->id, 'subject' => 'a ticket', 'priority' => TicketPriority::High]);

        $this->assertSame($withTwo, $count());
    }
}
