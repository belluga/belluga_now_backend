<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class MapFiltersRequest extends FormRequest
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
            'ne_lat' => 'sometimes|numeric|between:-90,90',
            'ne_lng' => 'sometimes|numeric|between:-180,180',
            'sw_lat' => 'sometimes|numeric|between:-90,90',
            'sw_lng' => 'sometimes|numeric|between:-180,180',
            'origin_lat' => 'sometimes|required_with:origin_lng|numeric|between:-90,90',
            'origin_lng' => 'sometimes|required_with:origin_lat|numeric|between:-180,180',
            'max_distance_meters' => 'sometimes|numeric|min:0',
            'categories' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'categories.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'tags.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'taxonomy' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'search' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
        ];
    }
}
