<?php

namespace App\Http\Requests\Api\Ticket;

use App\DTOs\Ticket\UpdateTicketDTO;
use App\Enums\TicketPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            // Priority drives the SLA deadline, so customers cannot change it after creating.
            'priority' => [
                Rule::prohibitedIf(fn () => $this->user()->isCustomer()),
                'sometimes',
                Rule::enum(TicketPriority::class),
            ],
            'category_id' => [
                'sometimes',
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

    public function toDTO(): UpdateTicketDTO
    {
        $priority = $this->validated('priority');

        return new UpdateTicketDTO(
            subject: $this->validated('subject'),
            description: $this->validated('description'),
            priority: $priority ? TicketPriority::from($priority) : null,
            categoryId: $this->has('category_id') ? (int) $this->validated('category_id') : null,
        );
    }
}
