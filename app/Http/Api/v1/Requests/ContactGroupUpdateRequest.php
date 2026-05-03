<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactGroupUpdateRequest extends FormRequest
{
    private const int MAX_GROUP_MEMBERS = 200;

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
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'recipient_account_profile_ids' => ['sometimes', 'array', 'max:'.self::MAX_GROUP_MEMBERS],
            'recipient_account_profile_ids.*' => ['string', 'max:255'],
        ];
    }
}
