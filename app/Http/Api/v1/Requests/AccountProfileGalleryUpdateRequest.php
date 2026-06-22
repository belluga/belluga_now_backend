<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

final class AccountProfileGalleryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawGroups = $this->input('gallery_groups');
        if (! is_string($rawGroups)) {
            return;
        }

        $decoded = json_decode($rawGroups, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return;
        }

        $this->merge([
            'gallery_groups' => $decoded,
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'gallery_groups' => 'required|array|max:'.InputConstraints::ACCOUNT_PROFILE_GALLERY_GROUPS_MAX,
            'gallery_groups.*.group_id' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_GALLERY_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'gallery_groups.*.subtitle' => 'required_with:gallery_groups|string|max:'.InputConstraints::NAME_MAX,
            'gallery_groups.*.order' => 'sometimes|integer|min:0',
            'gallery_groups.*.items' => 'required_with:gallery_groups|array|min:1',
            'gallery_groups.*.items.*.item_id' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_GALLERY_KEY_MAX.'|regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            'gallery_groups.*.items.*.description' => 'sometimes|nullable|string|max:'.InputConstraints::DESCRIPTION_MAX,
            'gallery_groups.*.items.*.order' => 'sometimes|integer|min:0',
            'gallery_groups.*.items.*.upload' => 'sometimes|nullable|string|max:'.InputConstraints::ACCOUNT_PROFILE_GALLERY_KEY_MAX,
        ];
    }
}
