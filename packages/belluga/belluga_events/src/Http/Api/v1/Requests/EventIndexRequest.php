<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventIndexRequest extends FormRequest
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
            'page' => 'sometimes|integer|min:1',
            'page_size' => 'sometimes|integer|min:1',
            // MVP: management listing does not support text search.
            'search' => 'prohibited',
            'archived' => 'sometimes|boolean',
            'status' => [
                'sometimes',
                'string',
                Rule::in(['published', 'publish_scheduled', 'draft', 'ended']),
            ],
        ];
    }
}
