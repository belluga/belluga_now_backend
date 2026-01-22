<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
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
            'search' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'archived' => 'sometimes|boolean',
            'account_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'account_profile_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'status' => [
                'sometimes',
                'string',
                Rule::in(['published', 'publish_scheduled', 'draft', 'ended']),
            ],
        ];
    }
}
