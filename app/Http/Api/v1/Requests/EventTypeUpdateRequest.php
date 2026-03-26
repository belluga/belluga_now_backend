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
            'icon' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::NAME_MAX],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
