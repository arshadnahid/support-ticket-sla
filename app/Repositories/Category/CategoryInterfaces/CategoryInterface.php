<?php

namespace App\Repositories\Category\CategoryInterfaces;

use App\DTOs\Category\CategoryFilterDTO;
use App\Models\Category;
use Illuminate\Pagination\LengthAwarePaginator;

interface CategoryInterface
{
    /**
     * Categories that can be chosen for a new ticket, ordered by name.
     *
     * @return LengthAwarePaginator<int, Category>
     */
    public function getActiveCategories(CategoryFilterDTO $filter): LengthAwarePaginator;
}
