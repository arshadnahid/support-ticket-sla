<?php

namespace Tests\Feature\Ticket;

use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for ticket tests: one user per role, a category, and a helper to build tickets.
 */
abstract class TicketTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected User $otherAgent;

    protected User $customer;

    protected User $otherCustomer;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->agent = User::factory()->agent()->create();
        $this->otherAgent = User::factory()->agent()->create();
        $this->customer = User::factory()->customer()->create();
        $this->otherCustomer = User::factory()->customer()->create();
        $this->category = Category::factory()->create();
    }

    protected function as(User $user): static
    {
        return $this->actingAs($user, 'api');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function ticketFor(User $customer, array $attributes = []): Ticket
    {
        return Ticket::factory()
            ->createdBy($customer)
            ->create(['category_id' => $this->category->id] + $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'subject' => 'Cannot log in',
            'description' => 'I get an error after entering my password.',
            'priority' => 'high',
            'category_id' => $this->category->id,
        ];
    }
}
