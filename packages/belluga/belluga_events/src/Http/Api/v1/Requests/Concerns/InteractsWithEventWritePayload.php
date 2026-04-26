<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests\Concerns;

use Belluga\Events\Support\Validation\EventPayloadFanoutGuard;
use Illuminate\Validation\Validator;

trait InteractsWithEventWritePayload
{
    protected function prepareForValidation(): void
    {
        $eventParties = $this->decodeJsonArrayField($this->input('event_parties'));
        if ($eventParties !== null) {
            $this->merge([
                'event_parties' => $eventParties,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (EventPayloadFanoutGuard::validate($this->all()) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    private function decodeJsonArrayField(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
