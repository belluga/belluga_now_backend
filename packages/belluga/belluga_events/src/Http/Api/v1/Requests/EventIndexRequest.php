<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $temporal = $this->input('temporal');
        if (is_string($temporal)) {
            $parts = array_values(array_filter(
                array_map(static fn (string $part): string => trim($part), explode(',', $temporal)),
                static fn (string $part): bool => $part !== ''
            ));
            $this->merge(['temporal' => $parts]);
        }
    }

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
            'page' => 'sometimes|integer|min:1',
            'page_size' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'archived' => 'sometimes|boolean',
            'status' => [
                'sometimes',
                'string',
                Rule::in(['published', 'publish_scheduled', 'draft', 'ended']),
            ],
            'temporal' => 'sometimes|array',
            'temporal.*' => [
                'string',
                Rule::in(['past', 'now', 'future']),
            ],
        ];
    }
}
