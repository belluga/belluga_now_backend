<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class StaticAssetUpdateRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'profile_type' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'display_name' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'location' => 'sometimes|array',
            'location.lat' => 'required_with:location.lng|numeric',
            'location.lng' => 'required_with:location.lat|numeric',
            'taxonomy_terms' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'tags' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'tags.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'categories' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'categories.*' => 'string|max:' . InputConstraints::NAME_MAX,
            'bio' => 'sometimes|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'content' => 'sometimes|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'is_active' => 'sometimes|boolean',
            'avatar' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:' . InputConstraints::IMAGE_MAX_KB,
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:' . InputConstraints::IMAGE_MAX_KB,
            'avatar_url' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'cover_url' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
        ];
    }
}
