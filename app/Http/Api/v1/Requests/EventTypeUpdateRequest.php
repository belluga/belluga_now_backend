<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class EventTypeUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'slug' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX, 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::DESCRIPTION_MAX],
            'allowed_taxonomies' => ['sometimes', 'array'],
            'allowed_taxonomies.*' => ['string', 'max:'.InputConstraints::NAME_MAX, 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/'],
            'visual' => ['sometimes', 'nullable', 'array'],
            'visual.mode' => ['required_with:visual', 'string', 'in:icon,image'],
            'visual.icon' => ['required_if:visual.mode,icon', 'string', 'max:'.InputConstraints::NAME_MAX],
            'visual.color' => ['required_if:visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'visual.icon_color' => ['required_if:visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'visual.image_source' => ['required_if:visual.mode,image', 'string', 'in:cover,type_asset'],
            'poi_visual' => ['sometimes', 'nullable', 'array'],
            'poi_visual.mode' => ['required_with:poi_visual', 'string', 'in:icon,image'],
            'poi_visual.icon' => ['required_if:poi_visual.mode,icon', 'string', 'max:'.InputConstraints::NAME_MAX],
            'poi_visual.color' => ['required_if:poi_visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'poi_visual.icon_color' => ['required_if:poi_visual.mode,icon', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'poi_visual.image_source' => ['required_if:poi_visual.mode,image', 'string', 'in:cover,type_asset'],
            'type_asset' => ['sometimes', 'nullable', 'image'],
            'remove_type_asset' => ['sometimes', 'boolean'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::NAME_MAX],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
