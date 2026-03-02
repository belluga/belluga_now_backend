<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventPartyCandidatesRequest extends FormRequest
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
            'search' => 'sometimes|string|max:120',
            'limit' => 'sometimes|integer|min:1|max:100',
        ];
    }
}

