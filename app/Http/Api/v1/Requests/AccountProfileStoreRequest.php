<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Http\Api\v1\Requests\Concerns\ValidatesAccountProfileRichText;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileStoreRequest extends FormRequest
{
    use ValidatesAccountProfileRichText;

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
            'account_id' => 'required|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'profile_type' => 'required|string|max:'.InputConstraints::NAME_MAX,
            'display_name' => 'required|string|max:'.InputConstraints::NAME_MAX,
            'location' => 'sometimes|array',
            'location.lat' => 'required_with:location.lng|numeric',
            'location.lng' => 'required_with:location.lat|numeric',
            'taxonomy_terms' => 'sometimes|array|max:'.InputConstraints::METADATA_MAX_ITEMS,
            'taxonomy_terms.*.type' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'taxonomy_terms.*.value' => 'required_with:taxonomy_terms|string|max:'.InputConstraints::NAME_MAX,
            'nested_profile_groups' => 'sometimes|array|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUPS_MAX,
            'nested_profile_groups.*.id' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'nested_profile_groups.*.key' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'nested_profile_groups.*.label' => 'required_with:nested_profile_groups|string|max:'.InputConstraints::NAME_MAX,
            'nested_profile_groups.*.order' => 'sometimes|integer|min:0',
            'nested_profile_groups.*.account_profile_ids' => 'sometimes|array|max:'.InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_MEMBERS_MAX,
            'nested_profile_groups.*.account_profile_ids.*' => 'required_with:nested_profile_groups.*.account_profile_ids|string|size:'.InputConstraints::OBJECT_ID_LENGTH,
            'bio' => $this->optionalAccountProfileRichTextRule(),
            'content' => $this->optionalAccountProfileRichTextRule(),
            'avatar' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB,
            'cover' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:'.InputConstraints::IMAGE_MAX_KB,
            'avatar_url' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
            'cover_url' => 'sometimes|string|max:'.InputConstraints::NAME_MAX,
        ];
    }
}
