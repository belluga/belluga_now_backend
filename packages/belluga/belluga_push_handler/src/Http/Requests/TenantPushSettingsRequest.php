<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TenantPushSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'push' => ['required', 'array'],
            'push.max_ttl_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'push.throttles' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('push_message_routes')) {
                $validator->errors()->add('push_message_routes', 'Use /settings/push/route_types instead.');
            }
            if ($this->has('push_message_types')) {
                $validator->errors()->add('push_message_types', 'Use /settings/push/message_types instead.');
            }
            if ($this->has('push.message_routes')) {
                $validator->errors()->add('push.message_routes', 'Use /settings/push/route_types instead.');
            }
            if ($this->has('push.message_types')) {
                $validator->errors()->add('push.message_types', 'Use /settings/push/message_types instead.');
            }
            if ($this->has('push.types')) {
                $validator->errors()->add('push.types', 'Use /settings/push/message_types instead.');
            }
            if ($this->has('push.enabled')) {
                $validator->errors()->add('push.enabled', 'Use /settings/push/enable or /settings/push/disable instead.');
            }
            if ($this->has('max_ttl_days')) {
                $validator->errors()->add('max_ttl_days', 'Use push.max_ttl_days instead.');
            }
            if ($this->has('firebase')) {
                $validator->errors()->add('firebase', 'Use /settings/firebase instead.');
            }
            if ($this->has('telemetry')) {
                $validator->errors()->add('telemetry', 'Use /settings/telemetry instead.');
            }
        });
    }
}
