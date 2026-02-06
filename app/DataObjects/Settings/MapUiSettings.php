<?php

declare(strict_types=1);

namespace App\DataObjects\Settings;

class MapUiSettings
{
    public function __construct(
        public MapUiRadiusSettings $radius,
        public MapUiTimeWindowSettings $poiTimeWindowHours,
        public ?MapUiDefaultLocation $defaultLocation,
    ) {
    }

    public static function fromValue(mixed $value): self
    {
        $payload = SettingsPayload::toArray($value);

        return new self(
            radius: MapUiRadiusSettings::fromValue($payload['radius'] ?? null),
            poiTimeWindowHours: MapUiTimeWindowSettings::fromValue($payload['poi_time_window_hours'] ?? null),
            defaultLocation: MapUiDefaultLocation::fromValue($payload['default_location'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        $radius = $this->radius->toArray();
        if ($radius !== []) {
            $payload['radius'] = $radius;
        }

        $timeWindow = $this->poiTimeWindowHours->toArray();
        if ($timeWindow !== []) {
            $payload['poi_time_window_hours'] = $timeWindow;
        }

        if ($this->defaultLocation) {
            $payload['default_location'] = $this->defaultLocation->toArray();
        }

        return $payload;
    }
}
