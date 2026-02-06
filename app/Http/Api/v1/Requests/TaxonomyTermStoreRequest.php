<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TaxonomyTermStoreRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
            'name' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
        ];
    }
}
