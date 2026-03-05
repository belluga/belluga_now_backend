<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Tenants\TenantSettings;
use Belluga\Events\Contracts\EventRadiusSettingsContract;

class TenantRadiusSettingsAdapter implements EventRadiusSettingsContract
{
    private const DEFAULT_RADIUS_MIN_KM = 1.0;
    private const DEFAULT_RADIUS_KM = 5.0;
    private const DEFAULT_RADIUS_MAX_KM = 50.0;

    public function resolveRadiusSettings(): array
    {
        $settings = TenantSettings::current();
        $mapUi = $settings?->getAttribute('map_ui') ?? [];
        $radius = is_array($mapUi) ? ($mapUi['radius'] ?? []) : [];
        $radius = is_array($radius) ? $radius : [];

        $min = isset($radius['min_km']) ? (float) $radius['min_km'] : self::DEFAULT_RADIUS_MIN_KM;
        $default = isset($radius['default_km']) ? (float) $radius['default_km'] : self::DEFAULT_RADIUS_KM;
        $max = isset($radius['max_km']) ? (float) $radius['max_km'] : self::DEFAULT_RADIUS_MAX_KM;

        if ($min <= 0) {
            $min = self::DEFAULT_RADIUS_MIN_KM;
        }
        if ($max <= 0) {
            $max = self::DEFAULT_RADIUS_MAX_KM;
        }
        if ($default <= 0) {
            $default = self::DEFAULT_RADIUS_KM;
        }

        return [
            'min_km' => $min,
            'default_km' => min(max($default, $min), $max),
            'max_km' => $max,
        ];
    }
}
