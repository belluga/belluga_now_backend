<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartShowRequest extends FormRequest
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
            'hold_token' => ['required', 'string', 'max:255'],
        ];
    }
}
