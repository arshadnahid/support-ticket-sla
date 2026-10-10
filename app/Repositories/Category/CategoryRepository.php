<?php

namespace App\Repositories\Category;

use App\Models\Category;
use App\Repositories\Category\CategoryInterfaces\CategoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository implements CategoryInterface
{
    public function active(): Collection
    {
        return Category::query()->active()->orderBy('name')->get(['id', 'name', 'slug']);
    }
}
