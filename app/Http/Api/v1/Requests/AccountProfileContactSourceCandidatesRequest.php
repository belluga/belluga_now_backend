<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileContactSourceCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'exclude_account_profile_id' => ['sometimes', 'string', 'size:'.InputConstraints::OBJECT_ID_LENGTH],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.InputConstraints::PUBLIC_PAGE_MAX],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.InputConstraints::PUBLIC_PAGE_SIZE_MAX],
        ];
    }
}
