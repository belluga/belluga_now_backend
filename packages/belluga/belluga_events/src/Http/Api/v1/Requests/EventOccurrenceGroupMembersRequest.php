<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

final class EventOccurrenceGroupMembersRequest extends FormRequest
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
                'min:2',
                'max:400',
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
}
