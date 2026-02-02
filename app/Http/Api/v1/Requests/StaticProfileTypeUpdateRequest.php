<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class StaticProfileTypeUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:' . InputConstraints::NAME_MAX],
            'allowed_taxonomies' => ['sometimes', 'array'],
            'allowed_taxonomies.*' => ['string', 'max:' . InputConstraints::NAME_MAX],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.is_poi_enabled' => ['sometimes', 'boolean'],
            'capabilities.has_bio' => ['sometimes', 'boolean'],
            'capabilities.has_taxonomies' => ['sometimes', 'boolean'],
            'capabilities.has_avatar' => ['sometimes', 'boolean'],
            'capabilities.has_cover' => ['sometimes', 'boolean'],
            'capabilities.has_content' => ['sometimes', 'boolean'],
        ];
    }
}
