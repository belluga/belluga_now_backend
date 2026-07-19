<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Application\AccountProfiles\AccountProfileCandidateDiscoveryService;
use App\Application\AccountProfiles\AccountProfileNameSearchKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class AccountProfileCandidatesRequest extends FormRequest
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
            'scope' => ['bail', 'required', 'string', Rule::in(AccountProfileCandidateDiscoveryService::scopes())],
            'search' => [
                'bail',
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (AccountProfileNameSearchKey::normalizeRequestSearch($value) === null) {
                        $fail('The search must contain 2 to 400 UTF-8 characters and normalize to 2 to 100 ASCII characters.');
                    }
                },
            ],
            'page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'exclude_account_profile_id' => ['sometimes', 'string', 'regex:/^[a-f0-9]{24}$/i'],
        ];
    }

    public function normalizedSearch(): string
    {
        $normalized = AccountProfileNameSearchKey::normalizeRequestSearch($this->input('search'));
        if ($normalized === null) {
            throw new LogicException('Validated candidate search could not be normalized.');
        }

        return $normalized;
    }
}
