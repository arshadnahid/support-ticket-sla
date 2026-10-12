<?php

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaService;
use App\Services\Ticket\TicketNumberGenerator;
use Illuminate\Database\Seeder;

class TicketSeeder extends Seeder
{
    /**
     * Demo tickets in every status. Numbers come from the real generator so the
     * year counter stays consistent with the data.
     */
    public function run(TicketNumberGenerator $numbers, SlaService $sla): void
    {
        $customers = User::withRole(UserRole::Customer)->get();
        $agents = User::availableAgents()->get();
        $categories = Category::active()->get();

        if ($customers->isEmpty() || $agents->isEmpty() || $categories->isEmpty()) {
            return;
        }

        foreach (range(1, 30) as $i) {
            $status = fake()->randomElement(TicketStatus::cases());

            // Only some open tickets are still waiting for an agent; everything further along has one.
            $unassigned = $status === TicketStatus::Open && fake()->boolean(60);

            $factory = Ticket::factory()
                ->status($status)
                ->createdBy($customers->random());

            if (! $unassigned) {
                $factory = $factory->assignedTo($agents->random());
            }

            $createdAt = now()->subDays(fake()->numberBetween(0, 14))->subMinutes($i);
            $priority = fake()->randomElement(TicketPriority::cases());

            // Deadlines come from the real SLA rules, so the demo data shows a mix of
            // within / approaching / breached tickets.
            $factory->priority($priority)->create([
                'number' => $numbers->next(),
                'category_id' => $categories->random()->id,
                'created_at' => $createdAt,
            ] + $sla->deadlinesFor($priority, $createdAt));
        }
    }
}
