<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileProximityPreferencesUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requiresFixedReference = fn (): bool => $this->input('location_preference.mode') === 'fixed_reference';

        return [
            'max_distance_meters' => ['required', 'integer', 'min:1'],
            'location_preference' => ['required', 'array'],
            'location_preference.mode' => [
                'required',
                'string',
                Rule::in(['live_device_location', 'fixed_reference']),
            ],
            'location_preference.fixed_reference' => [
                Rule::requiredIf($requiresFixedReference),
                'nullable',
                'array',
            ],
            'location_preference.fixed_reference.source_kind' => [
                Rule::requiredIf($requiresFixedReference),
                'nullable',
                'string',
                Rule::in(['manual_coordinate', 'entity_reference']),
            ],
            'location_preference.fixed_reference.coordinate' => [
                Rule::requiredIf($requiresFixedReference),
                'nullable',
                'array',
            ],
            'location_preference.fixed_reference.coordinate.lat' => [
                Rule::requiredIf($requiresFixedReference),
                'numeric',
                'between:-90,90',
            ],
            'location_preference.fixed_reference.coordinate.lng' => [
                Rule::requiredIf($requiresFixedReference),
                'numeric',
                'between:-180,180',
            ],
            'location_preference.fixed_reference.label' => ['nullable', 'string', 'max:160'],
            'location_preference.fixed_reference.entity_namespace' => ['nullable', 'string', 'max:80'],
            'location_preference.fixed_reference.entity_type' => ['nullable', 'string', 'max:80'],
            'location_preference.fixed_reference.entity_id' => ['nullable', 'string', 'max:120'],
        ];
    }
}
