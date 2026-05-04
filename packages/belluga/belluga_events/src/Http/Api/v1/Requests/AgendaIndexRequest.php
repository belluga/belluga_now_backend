<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Belluga\Events\Support\Validation\InputConstraints;
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
            'page' => 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_MAX,
            'page_size' => 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_SIZE_MAX,
            'per_page' => 'sometimes|integer|min:1|max:'.InputConstraints::PUBLIC_PAGE_SIZE_MAX,
            'past_only' => 'sometimes|boolean|prohibited_if:live_now_only,true',
            'live_now_only' => 'sometimes|boolean|prohibited_if:past_only,true',
            'confirmed_only' => 'sometimes|boolean',
            'search' => 'sometimes|string|max:'.InputConstraints::NAME_MAX.'|prohibits:origin_lat,origin_lng,max_distance_meters',
            'categories' => 'sometimes|array|max:'.InputConstraints::PUBLIC_FILTER_LIST_VALUES_MAX,
            'categories.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array|max:'.InputConstraints::PUBLIC_FILTER_LIST_VALUES_MAX,
            'tags.*' => 'string|max:'.InputConstraints::NAME_MAX,
            'taxonomy' => 'sometimes|array|max:'.InputConstraints::PUBLIC_FILTER_LIST_VALUES_MAX,
            'taxonomy.*.type' => 'required_with:taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy.*.value' => 'required_with:taxonomy|string|max:'.InputConstraints::NAME_MAX,
            'occurrence_ids' => 'sometimes|array|max:'.InputConstraints::PUBLIC_FILTER_LIST_VALUES_MAX,
            'occurrence_ids.*' => 'string|max:'.InputConstraints::OBJECT_ID_LENGTH,
            'origin_lat' => 'nullable|numeric|between:-90,90|required_with:origin_lng|prohibits:search',
            'origin_lng' => 'nullable|numeric|between:-180,180|required_with:origin_lat|prohibits:search',
            'max_distance_meters' => 'sometimes|numeric|min:0|max:'.InputConstraints::PUBLIC_GEO_DISTANCE_MAX_METERS.'|prohibits:search',
        ];
    }
}
