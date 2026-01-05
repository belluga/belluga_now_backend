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
            'firebase' => ['required', 'array'],
            'firebase.apiKey' => ['required', 'string'],
            'firebase.appId' => ['required', 'string'],
            'firebase.projectId' => ['required', 'string'],
            'firebase.messagingSenderId' => ['required', 'string'],
            'firebase.storageBucket' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('push')) {
                $validator->errors()->add('push', 'Use /settings/push instead.');
            }
            if ($this->has('telemetry')) {
                $validator->errors()->add('telemetry', 'Use /settings/telemetry instead.');
            }
            if ($this->has('max_ttl_days')) {
                $validator->errors()->add('max_ttl_days', 'Use push.max_ttl_days instead.');
            }
        });
    }
}
