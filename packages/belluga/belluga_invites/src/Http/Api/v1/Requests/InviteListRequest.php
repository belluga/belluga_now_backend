<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteListRequest extends FormRequest
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
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
