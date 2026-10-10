<?php

namespace App\Repositories\Category\CategoryInterfaces;

use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;

interface CategoryInterface
{
    /**
     * Categories that can be chosen for a new ticket, ordered by name.
     *
     * @return Collection<int, Category>
     */
    public function active(): Collection;
}
