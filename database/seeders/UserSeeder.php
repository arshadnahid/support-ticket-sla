<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Demo accounts for each role. All use the password "12345678" (local/demo only).
     */
    private const USERS = [
        ['name' => 'Nahid', 'email' => 'nahid@gmail.com', 'role' => UserRole::Admin],
        ['name' => 'Admin', 'email' => 'admin@example.com', 'role' => UserRole::Admin],
        ['name' => 'Agent One', 'email' => 'agent1@example.com', 'role' => UserRole::Agent],
        ['name' => 'Agent Two', 'email' => 'agent2@example.com', 'role' => UserRole::Agent],
        ['name' => 'Agent Three', 'email' => 'agent3@example.com', 'role' => UserRole::Agent, 'is_available' => false],
        ['name' => 'Customer One', 'email' => 'customer1@example.com', 'role' => UserRole::Customer],
        ['name' => 'Customer Two', 'email' => 'customer2@example.com', 'role' => UserRole::Customer],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = Hash::make('12345678');

        foreach (self::USERS as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => $password,
                    'role' => $user['role'],
                    'is_active' => true,
                    'is_available' => $user['is_available'] ?? true,
                    'email_verified_at' => now(),
                ]
            );
        }

        // Extra customers for realistic ticket data.
        User::factory()->customer()->count(10)->create();
    }
}
