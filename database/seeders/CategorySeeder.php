<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    private const NAMES = [
        'General Inquiry',
        'Technical Issue',
        'Billing',
        'Account & Access',
        'Feature Request',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Category::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_active' => true]);
        }
    }
}
