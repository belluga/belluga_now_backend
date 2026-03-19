<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteActionRequest extends FormRequest
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
            'idempotency_key' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
