<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AccountProfileNestedGroupMembersRequest extends FormRequest
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
            'cursor' => ['sometimes', 'string'],
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
}
