<?php

namespace App\Http\Requests\Api\Auth;

use App\DTOs\Auth\LoginCredentialDTO;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    /*public function authorize(): bool
    {
        return false;
    }*/

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function toDTO(): LoginCredentialDTO
    {
        return new LoginCredentialDTO(
            email: $this->validated('email'),
            password: $this->validated('password'),
        );
    }
}
