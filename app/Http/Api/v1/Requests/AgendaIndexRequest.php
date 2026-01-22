<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AgendaIndexRequest extends FormRequest
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
            'past_only' => 'sometimes|boolean',
            'confirmed_only' => 'sometimes|boolean',
            'search' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'account_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'account_profile_id' => 'sometimes|string|size:' . InputConstraints::OBJECT_ID_LENGTH,
            'categories' => 'sometimes|array',
            'categories.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'taxonomy' => 'sometimes|array',
            'taxonomy.*.type' => 'required_with:taxonomy|string|max:' . InputConstraints::NAME_MAX,
            'taxonomy.*.value' => 'required_with:taxonomy|string|max:' . InputConstraints::NAME_MAX,
            'origin_lat' => 'nullable|numeric|required_with:origin_lng',
            'origin_lng' => 'nullable|numeric|required_with:origin_lat',
            'max_distance_meters' => 'sometimes|numeric|min:0',
        ];
    }
}
