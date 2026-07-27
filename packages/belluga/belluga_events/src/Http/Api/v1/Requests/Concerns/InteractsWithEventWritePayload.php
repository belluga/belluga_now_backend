<?php

declare(strict_types=1);

namespace Belluga\Events\Http\Api\v1\Requests\Concerns;

use Belluga\Events\Support\Validation\EventPayloadFanoutGuard;
use Illuminate\Validation\Validator;

trait InteractsWithEventWritePayload
{
    protected function prepareForValidation(): void
    {
        $profileGroups = $this->decodeJsonArrayField($this->input('profile_groups'));
        if ($profileGroups !== null) {
            $this->merge([
                'profile_groups' => $profileGroups,
            ]);
        }

        $occurrences = $this->decodeOccurrenceProfileGroupFields($this->input('occurrences'));
        if ($occurrences !== null) {
            $this->merge([
                'occurrences' => $occurrences,
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

    /**
     * @param  mixed  $occurrences
     * @return array<int|string, mixed>|null
     */
    private function decodeOccurrenceProfileGroupFields(mixed $occurrences): ?array
    {
        if (! is_array($occurrences)) {
            return null;
        }

        $didMutate = false;
        $normalized = $occurrences;

        foreach ($normalized as $index => $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }

            $profileGroups = $this->decodeJsonArrayField($occurrence['profile_groups'] ?? null);
            if ($profileGroups === null) {
                continue;
            }

            $normalized[$index]['profile_groups'] = $profileGroups;
            $didMutate = true;
        }

        return $didMutate ? $normalized : null;
    }
}
