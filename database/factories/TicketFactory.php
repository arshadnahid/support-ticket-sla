<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Unique placeholder. Real numbers come from TicketNumberGenerator (see TicketSeeder).
            'number' => 'TKT-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'status' => TicketStatus::Open,
            'category_id' => Category::factory(),
            'created_by' => User::factory()->customer(),
            'assigned_to' => null,
        ];
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'resolved_at' => $status === TicketStatus::Resolved ? now() : null,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->status(TicketStatus::InProgress);
    }

    public function resolved(): static
    {
        return $this->status(TicketStatus::Resolved);
    }

    public function closed(): static
    {
        return $this->status(TicketStatus::Closed);
    }

    public function assignedTo(User $agent): static
    {
        return $this->state(fn (array $attributes) => ['assigned_to' => $agent->id]);
    }

    public function createdBy(User $customer): static
    {
        return $this->state(fn (array $attributes) => ['created_by' => $customer->id]);
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes) => ['priority' => $priority]);
    }
}
