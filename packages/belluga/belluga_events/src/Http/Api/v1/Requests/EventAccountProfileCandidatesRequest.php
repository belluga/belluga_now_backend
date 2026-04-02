<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventAccountProfileCandidatesRequest extends FormRequest
{
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
            'type' => ['required', 'string', Rule::in(['artist', 'physical_host'])],
            'search' => 'sometimes|string|max:120',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page_size' => 'sometimes|integer|min:1|max:50',
        ];
    }
}
