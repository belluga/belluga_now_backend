<?php

declare(strict_types=1);

namespace App\DataObjects\Settings;

class MapUiRadiusSettings
{
    public function __construct(
        public ?float $minKm,
        public ?float $defaultKm,
        public ?float $maxKm,
    ) {
    }

    public static function fromValue(mixed $value): self
    {
        $payload = SettingsPayload::toArray($value);

        $min = isset($payload['min_km']) ? (float) $payload['min_km'] : null;
        $default = isset($payload['default_km']) ? (float) $payload['default_km'] : null;
        $max = isset($payload['max_km']) ? (float) $payload['max_km'] : null;

        return new self($min, $default, $max);
    }

    /**
     * @return array{min_km: float, default_km: float, max_km: float}
     */
    public function resolveWithDefaults(float $minDefault, float $defaultDefault, float $maxDefault): array
    {
        $min = ($this->minKm !== null && $this->minKm > 0) ? $this->minKm : $minDefault;
        $max = ($this->maxKm !== null && $this->maxKm > 0) ? $this->maxKm : $maxDefault;

        if ($max < $min) {
            $max = max($maxDefault, $min);
        }

        $default = ($this->defaultKm !== null && $this->defaultKm > 0) ? $this->defaultKm : $defaultDefault;
        $default = min(max($default, $min), $max);

        return [
            'min_km' => $min,
            'default_km' => $default,
            'max_km' => $max,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->minKm !== null) {
            $payload['min_km'] = $this->minKm;
        }
        if ($this->defaultKm !== null) {
            $payload['default_km'] = $this->defaultKm;
        }
        if ($this->maxKm !== null) {
            $payload['max_km'] = $this->maxKm;
        }

        return $payload;
    }
}
