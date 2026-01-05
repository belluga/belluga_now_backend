<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantTelemetryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['mixpanel', 'firebase', 'webhook'])],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
            'token' => ['required_if:type,mixpanel', 'string'],
            'url' => ['required_if:type,webhook', 'string'],
        ];
    }
}
