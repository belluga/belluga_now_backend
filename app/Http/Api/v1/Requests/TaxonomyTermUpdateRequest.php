<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TaxonomyTermUpdateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'name' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
        ];
    }
}
