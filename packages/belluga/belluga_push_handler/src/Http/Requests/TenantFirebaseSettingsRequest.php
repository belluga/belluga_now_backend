<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TenantFirebaseSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'apiKey' => ['sometimes', 'string'],
            'appId' => ['sometimes', 'string'],
            'projectId' => ['sometimes', 'string'],
            'messagingSenderId' => ['sometimes', 'string'],
            'storageBucket' => ['sometimes', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! is_array($this->all()) || array_is_list($this->all())) {
                $validator->errors()->add('payload', 'The payload must be an object/map.');

                return;
            }

            $allowedKeys = ['apiKey', 'appId', 'projectId', 'messagingSenderId', 'storageBucket'];
            $disallowedMessages = [
                'firebase' => 'Use direct payload keys for firebase settings instead of a firebase envelope.',
                'push' => 'Use /settings/push instead.',
                'telemetry' => 'Use /settings/telemetry instead.',
                'max_ttl_days' => 'Use /settings/push with max_ttl_days instead.',
            ];

            $input = $this->all();
            foreach ($disallowedMessages as $key => $message) {
                if ($this->has($key)) {
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
                $validator->errors()->add('payload', 'At least one patchable key is required (apiKey, appId, projectId, messagingSenderId, storageBucket).');
            }
        });
    }
}
