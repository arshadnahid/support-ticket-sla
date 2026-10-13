<?php

namespace App\Http\Requests\Api\Ticket;

use App\DTOs\Category\CategoryFilterDTO;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('tickets.max_per_page')],
        ];
    }

    public function toDTO(): CategoryFilterDTO
    {
        return new CategoryFilterDTO(
            $this->validated('name'),
            (int) $this->validated('per_page', config('tickets.per_page')),
        );
    }
}
