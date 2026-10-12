<?php

namespace App\Http\Requests\Api\Ticket;

use App\Enums\TicketSort;
use App\Enums\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTicketsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('tickets.max_per_page')],
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'sort' => ['sometimes', Rule::enum(TicketSort::class)],
        ];
    }

    public function status(): ?TicketStatus
    {
        return TicketStatus::tryFrom((string) $this->validated('status', ''));
    }

    public function sort(): ?TicketSort
    {
        return TicketSort::tryFrom((string) $this->validated('sort', ''));
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('tickets.per_page'));
    }
}
