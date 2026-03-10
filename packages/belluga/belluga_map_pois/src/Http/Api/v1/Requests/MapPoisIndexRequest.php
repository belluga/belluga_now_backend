<?php

declare(strict_types=1);

namespace Belluga\MapPois\Http\Api\v1\Requests;

use Belluga\MapPois\Support\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapPoisIndexRequest extends FormRequest
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
            'source' => [
                'sometimes',
                'string',
                Rule::in(['event', 'account_profile', 'account', 'static', 'static_asset', 'asset']),
            ],
            'types' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'types.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'categories' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'categories.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'tags.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'taxonomy' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'search' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'sort' => [
                'sometimes',
                'string',
                Rule::in(['priority', 'distance', 'time_to_event']),
            ],
            'stack_key' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
        ];
    }
}
