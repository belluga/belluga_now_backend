<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactsImportRequest extends FormRequest
{
    /**
     * The payload carries hashed import items, not raw address-book contacts.
     * One device contact can expand into multiple phone/email hash variants.
     */
    private const int MAX_CONTACT_IMPORT_ITEMS = 5000;

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
            'contacts' => ['required', 'array', 'min:1', 'max:'.self::MAX_CONTACT_IMPORT_ITEMS],
            'contacts.*.type' => ['required', Rule::in(['phone', 'email'])],
            'contacts.*.hash' => ['required', 'string', 'max:255'],
            'salt_version' => ['nullable', 'string', 'max:255'],
        ];
    }
}
