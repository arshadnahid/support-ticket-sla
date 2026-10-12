<?php

namespace App\Http\Requests\Api\Ticket;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreReplyRequest extends FormRequest
{
    /**
     * Multipart forms send booleans as text ("true", "false"), which Laravel's `boolean` rule
     * rejects. Convert them first; anything unrecognisable is left alone and fails validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_internal')) {
            $value = filter_var($this->input('is_internal'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($value !== null) {
                $this->merge(['is_internal' => $value]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'is_internal' => ['sometimes', 'boolean'],
        ] + AttachmentRules::forField('attachments');
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }

    public function isInternal(): bool
    {
        return (bool) $this->validated('is_internal', false);
    }

    /**
     * @return list<UploadedFile>
     */
    public function files(): array
    {
        return array_values($this->file('attachments', []));
    }
}
