<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileTypeUpdateRequest extends FormRequest
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
            'capabilities.is_favoritable' => ['sometimes', 'boolean'],
            'capabilities.is_poi_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
