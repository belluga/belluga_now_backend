<?php

declare(strict_types=1);

namespace Belluga\MapPois\Http\Api\v1\Requests;

use Belluga\MapPois\Support\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class MapPoisNearRequest extends FormRequest
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
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'max_distance_meters' => 'sometimes|numeric|min:0',
            'categories' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'categories.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'tags.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'taxonomy' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'search' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'page' => 'sometimes|integer|min:1',
            'page_size' => 'sometimes|integer|min:1|max:50',
        ];
    }
}
