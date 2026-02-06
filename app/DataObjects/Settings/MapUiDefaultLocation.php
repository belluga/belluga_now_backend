<?php

declare(strict_types=1);

namespace App\DataObjects\Settings;

class MapUiDefaultLocation
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
    }

    public static function fromValue(mixed $value): ?self
    {
        $payload = SettingsPayload::toArray($value);

        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return new self((float) $lat, (float) $lng);
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}
