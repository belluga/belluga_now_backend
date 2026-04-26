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
            'page' => $this->pageRule(),
            'page_size' => 'sometimes|integer|min:1|max:'.$this->pageSizeMaximum(),
            'archived' => 'sometimes|boolean',
            'date' => 'sometimes|date_format:Y-m-d',
            'search' => 'prohibited',
            'venue_profile_id' => 'sometimes|string|max:'.InputConstraints::OBJECT_ID_LENGTH,
            'related_account_profile_id' => 'sometimes|string|max:'.InputConstraints::OBJECT_ID_LENGTH,
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

    private function pageSizeMaximum(): int
    {
        if ($this->route('account_slug') || str_starts_with($this->path(), 'admin/api/v1')) {
            return 100;
        }

        return InputConstraints::PUBLIC_PAGE_SIZE_MAX;
    }

    private function pageRule(): string
    {
        if ($this->route('account_slug') || str_starts_with($this->path(), 'admin/api/v1')) {
            return 'sometimes|integer|min:1';
        }

        return 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_MAX;
    }
}
