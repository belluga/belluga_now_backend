<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountOnboardingStoreRequest extends FormRequest
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
            'name' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'ownership_state' => 'required|string|in:tenant_owned,unmanaged',
            'profile_type' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'location' => 'sometimes|array',
            'location.lat' => 'required_with:location.lng|numeric',
            'location.lng' => 'required_with:location.lat|numeric',
            'taxonomy_terms' => 'sometimes|array|max:' . InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:' . InputConstraints::NAME_MAX,
            'bio' => 'sometimes|nullable|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'content' => 'sometimes|nullable|string|max:' . InputConstraints::DESCRIPTION_MAX,
            'avatar' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:' . InputConstraints::IMAGE_MAX_KB,
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:' . InputConstraints::IMAGE_MAX_KB,
        ];
    }
}

