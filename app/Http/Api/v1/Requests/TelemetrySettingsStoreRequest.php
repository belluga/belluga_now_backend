<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TelemetrySettingsStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(['mixpanel', 'webhook'])],
            'track_all' => ['sometimes', 'boolean'],
            'events' => [
                'array',
                Rule::requiredIf(fn () => ! $this->boolean('track_all')),
            ],
            'events.*' => ['string'],
            'token' => ['required_if:type,mixpanel', 'string'],
            'url' => ['required_if:type,webhook', 'url'],
        ];

        $availableEvents = config('telemetry.available_events', []);
        if (is_array($availableEvents) && $availableEvents !== []) {
            $rules['events.*'][] = Rule::in($availableEvents);
        }

        return $rules;
    }
}
