<?php

declare(strict_types=1);

namespace Belluga\Favorites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FavoritesIndexRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1'],
            'registry_key' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]*$/'],
            'target_type' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
