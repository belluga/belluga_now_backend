<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushQuotaCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audience_size' => ['required', 'integer', 'min:1'],
            'message_type' => ['nullable', 'string'],
            'push_message_id' => ['nullable', 'string'],
        ];
    }
}
