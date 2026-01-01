<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantPushSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_ttl_days' => ['required', 'integer', 'min:1', 'max:30'],
            'push_message_types' => ['required', 'array', 'min:1'],
            'push_message_types.*.key' => ['required', 'string'],
            'push_message_types.*.label' => ['required', 'string'],
            'push_message_types.*.description' => ['nullable', 'string'],
            'push_message_types.*.default_audience_type' => ['nullable', Rule::in(['all', 'users', 'event'])],
            'push_message_types.*.default_event_qualifier' => ['nullable', Rule::in([
                'event.confirmed',
                'event.invited',
                'event.all',
                'event.sent_invites',
            ])],
            'push_message_types.*.throttles' => ['nullable', 'array'],
            'push_message_routes' => ['nullable', 'array'],
            'push_message_routes.*.key' => ['required_with:push_message_routes', 'string'],
            'push_message_routes.*.path' => ['required_with:push_message_routes', 'string'],
            'push_message_routes.*.path_params' => ['nullable', 'array'],
            'push_message_routes.*.path_params.*' => ['string'],
            'push_message_routes.*.query_params' => ['nullable', 'array'],
            'push_message_routes.*.query_params.*' => ['string'],
            'telemetry' => ['nullable', 'array'],
            'telemetry.*.type' => ['required_with:telemetry', Rule::in(['mixpanel', 'firebase', 'webhook'])],
            'telemetry.*.events' => ['required_with:telemetry', 'array', 'min:1'],
            'telemetry.*.events.*' => ['string'],
            'telemetry.*.token' => ['required_if:telemetry.*.type,mixpanel', 'string'],
            'telemetry.*.url' => ['required_if:telemetry.*.type,webhook', 'string'],
            'firebase' => ['nullable', 'array'],
            'firebase.apiKey' => ['nullable', 'string'],
            'firebase.appId' => ['nullable', 'string'],
            'firebase.projectId' => ['nullable', 'string'],
            'firebase.messagingSenderId' => ['nullable', 'string'],
            'firebase.storageBucket' => ['nullable', 'string'],
            'firebase_credentials_id' => ['nullable', 'string'],
            'push' => ['nullable', 'array'],
            'push.enabled' => ['nullable', 'boolean'],
            'push.types' => ['nullable', 'array'],
            'push.types.*' => ['string'],
            'push.throttles' => ['nullable', 'array'],
        ];
    }
}
