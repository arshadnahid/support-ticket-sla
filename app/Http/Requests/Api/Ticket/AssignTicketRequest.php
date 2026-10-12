<?php

namespace App\Http\Requests\Api\Ticket;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Must be an enabled support agent. Availability is NOT required: an admin may
            // hand a ticket to an agent who is marked "unavailable" for automatic assignment.
            'agent_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::Agent->value)
                    ->where('is_active', true),
            ],
        ];
    }

    public function agentId(): int
    {
        return (int) $this->validated('agent_id');
    }
}
