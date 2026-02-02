<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TaxonomyUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string', 'max:' . InputConstraints::NAME_MAX],
            'name' => ['sometimes', 'string', 'max:' . InputConstraints::NAME_MAX],
            'applies_to' => ['sometimes', 'array', 'min:1', 'max:' . InputConstraints::METADATA_MAX_ITEMS],
            'applies_to.*' => ['required', 'string', 'in:account_profile,static_asset,event'],
            'icon' => ['nullable', 'string', 'max:' . InputConstraints::NAME_MAX],
            'color' => ['nullable', 'string', 'max:' . InputConstraints::NAME_MAX, 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
