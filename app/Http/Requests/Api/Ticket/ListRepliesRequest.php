<?php

namespace App\Http\Requests\Api\Ticket;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListRepliesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('tickets.max_per_page')],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('tickets.per_page'));
    }
}
