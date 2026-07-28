<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Application\AccountProfiles\AccountProfileNameSearchKey;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class AccountProfilePublicIndexRequest extends FormRequest
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
            ...AccountProfilePublicFilterRules::commonRules(),
            'per_page' => 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_SIZE_MAX,
        ];
    }

    public function normalizedSearch(): string
    {
        $rawSearch = $this->input('search');
        if ($rawSearch === null) {
            return '';
        }

        $normalized = AccountProfileNameSearchKey::normalizeRequestSearch($rawSearch);
        if ($normalized === null) {
            throw new LogicException('Validated public search could not be normalized.');
        }

        return $normalized;
    }
}
