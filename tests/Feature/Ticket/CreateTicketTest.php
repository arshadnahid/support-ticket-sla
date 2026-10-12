<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;
use App\Events\Ticket\TicketCreated;
use App\Models\Category;
use App\Models\Ticket;
use Illuminate\Support\Facades\Event;

class CreateTicketTest extends TicketTestCase
{
    public function test_customer_can_create_a_ticket(): void
    {
        Event::fake([TicketCreated::class]);

        $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status.value', 'open')
            ->assertJsonPath('data.priority.value', 'high')
            ->assertJsonPath('data.created_by.id', $this->customer->id)
            ->assertJsonPath('data.assigned_to', null)
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.number', 'TKT-'.now()->year.'-000001');

        $this->assertDatabaseHas('tickets', [
            'created_by' => $this->customer->id,
            'status' => TicketStatus::Open->value,
        ]);

        Event::assertDispatched(TicketCreated::class);
    }

    public function test_ticket_numbers_are_sequential_and_unique(): void
    {
        $numbers = collect(range(1, 3))->map(fn () => $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload())
            ->json('data.number'));

        $year = now()->year;

        $this->assertSame(["TKT-$year-000001", "TKT-$year-000002", "TKT-$year-000003"], $numbers->all());
        $this->assertSame(3, Ticket::distinct('number')->count('number'));
    }

    public function test_priority_defaults_to_medium(): void
    {
        $payload = $this->validPayload();
        unset($payload['priority']);

        $this->as($this->customer)
            ->postJson('/api/tickets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.priority.value', 'medium');
    }

    public function test_creation_is_validated(): void
    {
        $this->as($this->customer)
            ->postJson('/api/tickets', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'description', 'category_id']);

        $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload(['priority' => 'urgent', 'subject' => str_repeat('a', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority', 'subject']);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_inactive_or_unknown_category_is_rejected(): void
    {
        $inactive = Category::factory()->inactive()->create();

        $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload(['category_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload(['category_id' => 9999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_client_cannot_set_protected_fields(): void
    {
        $this->as($this->customer)
            ->postJson('/api/tickets', $this->validPayload([
                'status' => 'closed',
                'created_by' => $this->otherCustomer->id,
                'assigned_to' => $this->agent->id,
                'number' => 'TKT-1999-000001',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'open')
            ->assertJsonPath('data.created_by.id', $this->customer->id)
            ->assertJsonPath('data.assigned_to', null);
    }

    public function test_guests_cannot_create_tickets(): void
    {
        $this->postJson('/api/tickets', $this->validPayload())->assertUnauthorized();
    }

    public function test_only_customers_can_create_tickets(): void
    {
        $this->as($this->agent)->postJson('/api/tickets', $this->validPayload())->assertForbidden();
        $this->as($this->admin)->postJson('/api/tickets', $this->validPayload())->assertForbidden();

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_categories_endpoint_lists_only_active_categories(): void
    {
        Category::factory()->inactive()->create(['name' => 'Retired']);

        $names = $this->as($this->customer)
            ->getJson('/api/categories')
            ->assertOk()
            ->json('data.*.name');

        $this->assertContains($this->category->name, $names);
        $this->assertNotContains('Retired', $names);
    }
}
