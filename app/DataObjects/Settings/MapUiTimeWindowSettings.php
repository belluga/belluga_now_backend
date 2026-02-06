<?php

declare(strict_types=1);

namespace App\DataObjects\Settings;

class MapUiTimeWindowSettings
{
    public function __construct(
        public ?float $pastHours,
        public ?float $futureHours,
    ) {
    }

    public static function fromValue(mixed $value): self
    {
        $payload = SettingsPayload::toArray($value);

        $past = isset($payload['past']) ? (float) $payload['past'] : null;
        $future = isset($payload['future']) ? (float) $payload['future'] : null;

        return new self($past, $future);
    }

    /**
     * @return array{past_hours: float, future_hours: float}
     */
    public function resolveWithDefaults(float $pastDefault, float $futureDefault): array
    {
        $past = ($this->pastHours !== null && $this->pastHours > 0) ? $this->pastHours : $pastDefault;
        $future = ($this->futureHours !== null && $this->futureHours > 0) ? $this->futureHours : $futureDefault;

        return [
            'past_hours' => $past,
            'future_hours' => $future,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->pastHours !== null) {
            $payload['past'] = $this->pastHours;
        }
        if ($this->futureHours !== null) {
            $payload['future'] = $this->futureHours;
        }

        return $payload;
    }
}
