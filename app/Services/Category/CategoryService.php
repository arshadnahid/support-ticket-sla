<?php

namespace App\Services\Category;

use App\DTOs\Category\CategoryFilterDTO;
use App\Models\Category;
use App\Repositories\Category\CategoryInterfaces\CategoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryService
{
    public function __construct(
        private readonly CategoryInterface $categories
    ) {}

    /**
     * Categories that can be chosen for a new ticket, ordered by name.
     *
     * @return LengthAwarePaginator<int, Category>
     */
    public function getActiveCategories(CategoryFilterDTO $filter): LengthAwarePaginator
    {
        return $this->categories->getActiveCategories($filter);
    }
}
