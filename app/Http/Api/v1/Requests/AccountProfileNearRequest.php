<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileNearRequest extends FormRequest
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
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'max_distance_meters' => 'sometimes|numeric|min:0|max:'.InputConstraints::PUBLIC_GEO_DISTANCE_MAX_METERS,
            ...AccountProfilePublicFilterRules::commonRules(),
        ];
    }
}
