<?php

namespace App\Http\Requests\Api\Ticket;

use App\DTOs\Ticket\TicketListFilters;
use App\Enums\SlaState;
use App\Enums\TicketPriority;
use App\Enums\TicketSort;
use App\Enums\TicketStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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

            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'number' => ['sometimes', 'string', 'max:32'],
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
            'assigned_to' => ['sometimes', 'integer', 'prohibits:unassigned', Rule::exists('users', 'id')],
            'unassigned' => ['sometimes', 'boolean'],
            'customer_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sla' => ['sometimes', Rule::enum(SlaState::class)],
            'sort' => ['sometimes', Rule::enum(TicketSort::class)],
        ];
    }

    /**
     * Query strings send booleans as text ("true"); convert them so `boolean` accepts them.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('unassigned')) {
            $value = filter_var($this->input('unassigned'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $this->merge(['unassigned' => $value]);
            }
        }
    }

    public function filters(): TicketListFilters
    {
        $v = $this->validated();

        return new TicketListFilters(
            keyword: isset($v['q']) ? trim($v['q']) : null,
            number: isset($v['number']) ? strtoupper(trim($v['number'])) : null,
            status: TicketStatus::tryFrom($v['status'] ?? ''),
            priority: TicketPriority::tryFrom($v['priority'] ?? ''),
            categoryId: isset($v['category_id']) ? (int) $v['category_id'] : null,
            assignedTo: isset($v['assigned_to']) ? (int) $v['assigned_to'] : null,
            unassigned: (bool) ($v['unassigned'] ?? false),
            customerId: isset($v['customer_id']) ? (int) $v['customer_id'] : null,
            // Whole days, inclusive at both ends, in the application timezone (UTC).
            createdFrom: isset($v['created_from']) ? Carbon::parse($v['created_from'])->startOfDay() : null,
            createdTo: isset($v['created_to']) ? Carbon::parse($v['created_to'])->endOfDay() : null,
            sla: SlaState::tryFrom($v['sla'] ?? ''),
            sort: TicketSort::tryFrom($v['sort'] ?? ''),
        );
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', config('tickets.per_page'));
    }
}
