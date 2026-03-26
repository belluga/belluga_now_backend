<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\Media\MapFilterImageStorageService;
use App\Models\Tenants\TenantSettings;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;

class MapPoiSettingsAdapter implements MapPoiSettingsContract
{
    public function __construct(
        private readonly MapFilterImageStorageService $mapFilterImageStorageService,
    ) {}

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

        if (! is_array($mapUi)) {
            return [];
        }

        $filters = $mapUi['filters'] ?? null;
        if (! is_array($filters)) {
            return $mapUi;
        }

        $baseUrl = request()->getSchemeAndHttpHost();
        $normalizedFilters = [];

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                $normalizedFilters[] = $filter;

                continue;
            }

            $key = isset($filter['key']) && is_string($filter['key'])
                ? trim($filter['key'])
                : '';
            if ($key !== '') {
                $filter['image_uri'] = $this->mapFilterImageStorageService->normalizePublicUrl(
                    baseUrl: $baseUrl,
                    key: $key,
                    rawImageUri: isset($filter['image_uri']) && is_string($filter['image_uri'])
                        ? $filter['image_uri']
                        : null,
                );

                if (
                    (bool) ($filter['override_marker'] ?? false)
                    && is_array($filter['marker_override'] ?? null)
                    && strtolower(trim((string) ($filter['marker_override']['mode'] ?? ''))) === 'image'
                ) {
                    $filter['marker_override']['image_uri'] = $this->mapFilterImageStorageService->normalizePublicUrl(
                        baseUrl: $baseUrl,
                        key: $key,
                        rawImageUri: isset($filter['marker_override']['image_uri']) && is_string($filter['marker_override']['image_uri'])
                            ? $filter['marker_override']['image_uri']
                            : null,
                    );
                }
            }

            $normalizedFilters[] = $filter;
        }

        $mapUi['filters'] = $normalizedFilters;

        return $mapUi;
    }

    public function resolveMapIngestSettings(): array
    {
        $settings = TenantSettings::current();
        $mapIngest = $settings?->getAttribute('map_ingest') ?? [];

        return is_array($mapIngest) ? $mapIngest : [];
    }
}
