<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantPushMessageRoutesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            '*' => ['required', 'array'],
            '*.key' => ['required', 'string', 'distinct'],
            '*.path' => ['required', 'string'],
            '*.path_params' => ['nullable', 'array'],
            '*.path_params.*' => ['string'],
            '*.query_params' => ['nullable', 'array'],
            '*.query_params.*' => ['string'],
        ];
    }
}
