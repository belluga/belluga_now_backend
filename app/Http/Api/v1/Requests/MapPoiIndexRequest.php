<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapPoiIndexRequest extends FormRequest
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
            'viewport' => 'sometimes|array',
            'viewport.north' => 'required_with:viewport|numeric',
            'viewport.south' => 'required_with:viewport|numeric',
            'viewport.east' => 'required_with:viewport|numeric',
            'viewport.west' => 'required_with:viewport|numeric',
            'ne_lat' => 'sometimes|numeric',
            'ne_lng' => 'sometimes|numeric',
            'sw_lat' => 'sometimes|numeric',
            'sw_lng' => 'sometimes|numeric',
            'origin_lat' => 'nullable|numeric|required_with:origin_lng',
            'origin_lng' => 'nullable|numeric|required_with:origin_lat',
            'max_distance_meters' => 'sometimes|numeric|min:0',
            'search' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'categories' => 'sometimes|array',
            'categories.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'taxonomy' => 'sometimes|array',
            'taxonomy.*.type' => 'required_with:taxonomy|string|max:' . InputConstraints::NAME_MAX,
            'taxonomy.*.value' => 'required_with:taxonomy|string|max:' . InputConstraints::NAME_MAX,
            'sort' => [
                'sometimes',
                'string',
                Rule::in(['priority', 'distance', 'time_to_event']),
            ],
        ];
    }
}
