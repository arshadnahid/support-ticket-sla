<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class TicketListTest extends TicketTestCase
{
    public function test_customer_only_sees_own_tickets(): void
    {
        $mine = $this->ticketFor($this->customer);
        $this->ticketFor($this->otherCustomer);

        $this->as($this->customer)
            ->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $mine->number);
    }

    public function test_agent_sees_assigned_and_unassigned_but_not_other_agents_tickets(): void
    {
        $mine = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
        $unassigned = $this->ticketFor($this->customer);
        $this->ticketFor($this->customer, ['assigned_to' => $this->otherAgent->id]);

        $numbers = $this->as($this->agent)->getJson('/api/tickets')->json('data.*.number');

        $this->assertEqualsCanonicalizing([$mine->number, $unassigned->number], $numbers);
    }

    public function test_admin_sees_everything(): void
    {
        $this->ticketFor($this->customer);
        $this->ticketFor($this->otherCustomer, ['assigned_to' => $this->agent->id]);
        $this->ticketFor($this->otherCustomer, ['status' => TicketStatus::Closed]);

        $this->as($this->admin)->getJson('/api/tickets')->assertJsonCount(3, 'data');
    }

    public function test_active_and_resolved_lists_split_by_status(): void
    {
        $open = $this->ticketFor($this->customer);
        $progress = $this->ticketFor($this->customer, ['status' => TicketStatus::InProgress, 'assigned_to' => $this->agent->id]);
        $waiting = $this->ticketFor($this->customer, ['status' => TicketStatus::WaitingForCustomer, 'assigned_to' => $this->agent->id]);
        $resolved = $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'assigned_to' => $this->agent->id]);
        $closed = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'assigned_to' => $this->agent->id]);

        $active = $this->as($this->customer)->getJson('/api/tickets/active')->json('data.*.number');
        $finished = $this->as($this->customer)->getJson('/api/tickets/resolved')->json('data.*.number');

        $this->assertEqualsCanonicalizing([$open->number, $progress->number, $waiting->number], $active);
        $this->assertEqualsCanonicalizing([$resolved->number, $closed->number], $finished);
    }

    public function test_active_list_puts_the_earliest_deadline_first_and_no_deadline_last(): void
    {
        $noDue = $this->ticketFor($this->customer);
        $later = $this->ticketFor($this->customer, ['due_at' => now()->addDays(2)]);
        $sooner = $this->ticketFor($this->customer, ['due_at' => now()->addHours(2)]);

        $numbers = $this->as($this->customer)->getJson('/api/tickets/active')->json('data.*.number');

        $this->assertSame([$sooner->number, $later->number, $noDue->number], $numbers);
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        $this->ticketFor($this->customer);
        $closed = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed]);

        $this->as($this->customer)
            ->getJson('/api/tickets?status=closed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $closed->number);

        $this->as($this->customer)
            ->getJson('/api/tickets?status=nonsense')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_list_can_be_sorted(): void
    {
        $old = $this->ticketFor($this->customer, ['created_at' => now()->subDays(3), 'due_at' => now()->addDays(2)]);
        $mid = $this->ticketFor($this->customer, ['created_at' => now()->subDays(2)]);
        $new = $this->ticketFor($this->customer, ['created_at' => now()->subDay(), 'due_at' => now()->addHour()]);

        $sorted = fn (string $sort) => $this->as($this->customer)->getJson('/api/tickets?sort='.$sort)->json('data.*.number');

        $this->assertSame([$new->number, $mid->number, $old->number], $sorted('created_desc'));
        $this->assertSame([$old->number, $mid->number, $new->number], $sorted('created_asc'));
        $this->assertSame([$new->number, $old->number, $mid->number], $sorted('sla_deadline'));

        $this->as($this->customer)->getJson('/api/tickets?sort=bogus')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_sort_on_active_list_keeps_the_active_filter(): void
    {
        $open = $this->ticketFor($this->customer);
        $this->ticketFor($this->customer, ['status' => TicketStatus::Closed]);

        $this->as($this->customer)
            ->getJson('/api/tickets/active?sort=created_asc')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $open->number);
    }

    public function test_list_is_paginated(): void
    {
        Ticket::factory()->count(5)->createdBy($this->customer)->create(['category_id' => $this->category->id]);

        $this->as($this->customer)
            ->getJson('/api/tickets?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3);
    }

    public function test_per_page_is_limited(): void
    {
        $this->as($this->customer)
            ->getJson('/api/tickets?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_list_does_not_run_extra_queries_per_ticket(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->admin)->getJson('/api/tickets')->assertOk();

            return count(DB::getQueryLog());
        };

        Ticket::factory()->count(2)->assignedTo($this->agent)->create(['category_id' => $this->category->id]);
        $withTwo = $count();

        Ticket::factory()->count(20)->assignedTo($this->agent)->create(['category_id' => $this->category->id]);
        $withTwentyTwo = $count();

        // N+1 would add ~3 queries per extra ticket. The count must not grow.
        $this->assertSame($withTwo, $withTwentyTwo);
    }

    public function test_customers_never_see_staff_email_addresses(): void
    {
        $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);

        $this->as($this->customer)
            ->getJson('/api/tickets')
            ->assertJsonPath('data.0.assigned_to.name', $this->agent->name)
            ->assertJsonMissingPath('data.0.assigned_to.email');

        $this->as($this->admin)
            ->getJson('/api/tickets')
            ->assertJsonPath('data.0.assigned_to.email', $this->agent->email);
    }
}
