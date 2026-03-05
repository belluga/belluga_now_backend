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
            'max_ttl_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'throttles' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! is_array($this->all()) || array_is_list($this->all())) {
                $validator->errors()->add('payload', 'The payload must be an object/map.');
                return;
            }

            $allowedKeys = ['max_ttl_days', 'throttles'];
            $disallowedMessages = [
                'push' => 'Use direct payload keys (max_ttl_days/throttles) instead of a push envelope.',
                'push_message_routes' => 'Use /settings/push/route_types instead.',
                'push_message_types' => 'Use /settings/push/message_types instead.',
                'message_routes' => 'Use /settings/push/route_types instead.',
                'message_types' => 'Use /settings/push/message_types instead.',
                'types' => 'Use /settings/push/message_types instead.',
                'enabled' => 'Use /settings/push/enable or /settings/push/disable instead.',
                'firebase' => 'Use /settings/firebase instead.',
                'telemetry' => 'Use /settings/telemetry instead.',
            ];

            $input = $this->all();
            foreach ($disallowedMessages as $key => $message) {
                if ($this->has($key) || data_get($input, $key) !== null) {
                    $validator->errors()->add($key, $message);
                }
            }

            foreach (array_keys($input) as $key) {
                if (! in_array($key, $allowedKeys, true) && ! array_key_exists($key, $disallowedMessages)) {
                    $validator->errors()->add($key, 'Unsupported key for this PATCH endpoint.');
                }
            }

            $hasAllowed = false;
            foreach ($allowedKeys as $key) {
                if (array_key_exists($key, $input)) {
                    $hasAllowed = true;
                    break;
                }
            }

            if (! $hasAllowed) {
                $validator->errors()->add('payload', 'At least one patchable key is required (max_ttl_days, throttles).');
            }
        });
    }
}
