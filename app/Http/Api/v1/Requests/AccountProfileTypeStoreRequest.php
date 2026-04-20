<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileTypeStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX, 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'label' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX],
            'labels' => ['sometimes', 'array'],
            'labels.singular' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'labels.plural' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'allowed_taxonomies' => ['sometimes', 'array'],
            'allowed_taxonomies.*' => ['string', 'max:'.InputConstraints::NAME_MAX],
            'visual' => ['sometimes', 'nullable', 'array'],
            'visual.mode' => ['required_with:visual', 'string', 'in:icon,image'],
            'visual.icon' => ['required_if:visual.mode,icon', 'string', 'max:'.InputConstraints::NAME_MAX],
            'visual.color' => ['required_if:visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'visual.icon_color' => ['required_if:visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'visual.image_source' => ['required_if:visual.mode,image', 'string', 'in:avatar,cover,type_asset'],
            'poi_visual' => ['sometimes', 'nullable', 'array'],
            'poi_visual.mode' => ['required_with:poi_visual', 'string', 'in:icon,image'],
            'poi_visual.icon' => ['required_if:poi_visual.mode,icon', 'string', 'max:'.InputConstraints::NAME_MAX],
            'poi_visual.color' => ['required_if:poi_visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'poi_visual.icon_color' => ['required_if:poi_visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'poi_visual.image_source' => ['required_if:poi_visual.mode,image', 'string', 'in:avatar,cover,type_asset'],
            'type_asset' => ['sometimes', 'nullable', 'image'],
            'remove_type_asset' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.is_favoritable' => ['sometimes', 'boolean'],
            'capabilities.is_poi_enabled' => ['sometimes', 'boolean'],
            'capabilities.is_reference_location_enabled' => ['sometimes', 'boolean'],
            'capabilities.has_bio' => ['sometimes', 'boolean'],
            'capabilities.has_content' => ['sometimes', 'boolean'],
            'capabilities.has_taxonomies' => ['sometimes', 'boolean'],
            'capabilities.has_avatar' => ['sometimes', 'boolean'],
            'capabilities.has_cover' => ['sometimes', 'boolean'],
            'capabilities.has_events' => ['sometimes', 'boolean'],
        ];
    }
}
