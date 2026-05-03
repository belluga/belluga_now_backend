<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactsImportRequest extends FormRequest
{
    private const int MAX_CONTACTS_PER_IMPORT = 500;

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
            'contacts' => ['required', 'array', 'min:1', 'max:'.self::MAX_CONTACTS_PER_IMPORT],
            'contacts.*.type' => ['required', Rule::in(['phone', 'email'])],
            'contacts.*.hash' => ['required', 'string', 'max:255'],
            'salt_version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
