<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Models\Tenants\TenantSettings;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use MongoDB\Model\BSONDocument;

class MapPoiSettingsAdapter implements MapPoiSettingsContract
{
    public function resolveEventsSettings(): array
    {
        return $this->normalizeDocument(
            TenantSettings::current()?->getAttribute('events')
        );
    }

    public function resolveMapUiSettings(): array
    {
        $mapUi = $this->normalizeDocument(
            TenantSettings::current()?->getAttribute('map_ui')
        );
        unset($mapUi['filters']);

        return $mapUi;
    }

    public function resolveMapIngestSettings(): array
    {
        return $this->normalizeDocument(
            TenantSettings::current()?->getAttribute('map_ingest')
        );
    }

    /** @return array<string, mixed> */
    private function normalizeDocument(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof BSONDocument) {
            return $value->getArrayCopy();
        }

        return [];
    }
}
