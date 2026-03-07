<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Models\Tenants\TenantSettings;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;

class MapPoiSettingsAdapter implements MapPoiSettingsContract
{
    public function resolveEventsSettings(): array
    {
        $settings = TenantSettings::current();
        $events = $settings?->getAttribute('events') ?? [];

        return is_array($events) ? $events : [];
    }

    public function resolveMapUiSettings(): array
    {
        $settings = TenantSettings::current();
        $mapUi = $settings?->getAttribute('map_ui') ?? [];

        return is_array($mapUi) ? $mapUi : [];
    }

    public function resolveMapIngestSettings(): array
    {
        $settings = TenantSettings::current();
        $mapIngest = $settings?->getAttribute('map_ingest') ?? [];

        return is_array($mapIngest) ? $mapIngest : [];
    }
}
