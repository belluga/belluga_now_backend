<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class OrganizationUpdateRequest extends FormRequest
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
            'name' => 'sometimes|string|max:' . InputConstraints::NAME_MAX,
            'slug' => [
                'sometimes',
                'string',
                'max:' . InputConstraints::NAME_MAX,
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
            ],
            'description' => 'sometimes|string|max:' . InputConstraints::DESCRIPTION_MAX,
        ];
    }
}
