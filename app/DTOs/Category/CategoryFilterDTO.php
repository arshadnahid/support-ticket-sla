<?php

namespace App\DTOs\Category;

class CategoryFilterDTO
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly int $perPage = 15,
    ) {}
}
