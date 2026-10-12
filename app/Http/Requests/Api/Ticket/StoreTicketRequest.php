<?php

namespace App\Http\Requests\Api\Ticket;

use App\DTOs\Ticket\CreateTicketDTO;
use App\Enums\TicketPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            // Only active categories can be chosen for a new ticket.
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->where('is_active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'priority.enum' => 'The selected priority is invalid. Allowed values: '
                .implode(', ', array_column(TicketPriority::cases(), 'value')).'.',
        ];
    }

    public function toDTO(): CreateTicketDTO
    {
        return new CreateTicketDTO(
            subject: $this->validated('subject'),
            description: $this->validated('description'),
            // Missing or null priority falls back to medium.
            priority: TicketPriority::from($this->validated('priority') ?? TicketPriority::Medium->value),
            categoryId: (int) $this->validated('category_id'),
        );
    }
}
