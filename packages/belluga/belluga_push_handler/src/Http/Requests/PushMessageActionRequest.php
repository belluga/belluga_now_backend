<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushMessageActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['opened', 'clicked', 'dismissed', 'step_viewed', 'delivered'])],
            'step_index' => ['required', 'integer', 'min:0'],
            'button_key' => ['required_if:action,clicked', 'string'],
            'device_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
