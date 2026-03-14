<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactsImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.type' => ['required', Rule::in(['phone', 'email'])],
            'contacts.*.hash' => ['required', 'string', 'max:255'],
            'salt_version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
