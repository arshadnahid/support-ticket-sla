<?php

namespace App\Repositories\Category;

use App\DTOs\Category\CategoryFilterDTO;
use App\Models\Category;
use App\Repositories\Category\CategoryInterfaces\CategoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryRepository implements CategoryInterface
{
    public function getActiveCategories(CategoryFilterDTO $filter): LengthAwarePaginator
    {
        return Category::query()
            ->active()
            ->when($filter->name, fn ($q, $v) => $q->where('name', 'like', $this->like($v)))
            ->orderBy('name')
            ->orderBy('id')
            ->select(['id', 'name', 'slug'])
            ->paginate($filter->perPage)
            ->withQueryString();
    }

    /**
     * Contains-match pattern; % and _ typed by the client are escaped so they match literally.
     */
    private function like(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }
}
