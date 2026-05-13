<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PushMessageSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['nullable', 'string'],
            'dry_run' => ['nullable', 'boolean'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
        ];
    }
}
