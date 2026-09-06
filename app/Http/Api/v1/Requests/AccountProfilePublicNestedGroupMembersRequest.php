<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Application\AccountProfiles\AccountProfileSearchV1;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

final class AccountProfilePublicNestedGroupMembersRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => ['sometimes', 'string', 'max:'.InputConstraints::PAGINATION_CURSOR_MAX],
            'search' => [
                'sometimes',
                'nullable',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim((string) $value) !== '' && AccountProfileSearchV1::normalizeRequestSearch($value) === null) {
                        $fail('The search must normalize to 2 to 100 ASCII characters.');
                    }
                },
            ],
        ];
    }

    public function perPage(): int
    {
        return max(1, (int) $this->input('per_page', 20));
    }

    public function suppliedPerPage(): ?int
    {
        return $this->has('per_page') ? max(1, (int) $this->input('per_page')) : null;
    }

    public function cursor(): ?string
    {
        $cursor = $this->input('cursor');
        if (! is_string($cursor)) {
            return null;
        }

        $cursor = trim($cursor);

        return $cursor === '' ? null : $cursor;
    }

    public function normalizedSearch(): ?string
    {
        $raw = $this->input('search');

        return is_string($raw) && trim($raw) !== ''
            ? AccountProfileSearchV1::normalizeRequestSearch($raw)
            : null;
    }
}
