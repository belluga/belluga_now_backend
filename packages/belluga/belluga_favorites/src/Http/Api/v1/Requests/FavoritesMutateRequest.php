<?php

declare(strict_types=1);

namespace Belluga\Favorites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FavoritesMutateRequest extends FormRequest
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
        return [
            'target_id' => ['required', 'string', 'max:200'],
            'registry_key' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            'target_type' => ['sometimes', 'string', 'max:100'],
        ];
    }
}

