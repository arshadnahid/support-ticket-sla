<?php

namespace App\Http\Requests\Api\Ticket;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreAttachmentsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = AttachmentRules::forField('files');
        array_unshift($rules['files'], 'required');
        $rules['files'][] = 'min:1';

        return $rules;
    }

    /**
     * @return list<UploadedFile>
     */
    public function files(): array
    {
        return array_values($this->file('files', []));
    }
}
