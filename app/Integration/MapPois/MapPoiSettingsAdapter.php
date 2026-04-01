<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\Media\MapFilterImageStorageService;
use App\Models\Tenants\TenantSettings;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use MongoDB\Model\BSONDocument;

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
        $mapUi = $this->normalizeDocument($settings?->getAttribute('map_ui'));
        if ($mapUi === []) {
            return [];
        }

        $filters = $this->normalizeList($mapUi['filters'] ?? null);
        if ($filters === []) {
            return $mapUi;
        }

        $baseUrl = request()->getSchemeAndHttpHost();
        $normalizedFilters = [];

        foreach ($filters as $filter) {
            $normalizedFilter = $this->normalizeDocument($filter);
            if ($normalizedFilter === []) {
                $normalizedFilters[] = $filter;

                continue;
            }

            $key = isset($normalizedFilter['key']) && is_string($normalizedFilter['key'])
                ? trim($normalizedFilter['key'])
                : '';
            if ($key !== '') {
                $normalizedFilter['image_uri'] = $this->mapFilterImageStorageService->normalizePublicUrl(
                    baseUrl: $baseUrl,
                    key: $key,
                    rawImageUri: isset($normalizedFilter['image_uri']) && is_string($normalizedFilter['image_uri'])
                        ? $normalizedFilter['image_uri']
                        : null,
                );

                $markerOverride = $this->normalizeDocument($normalizedFilter['marker_override'] ?? null);
                if (
                    (bool) ($normalizedFilter['override_marker'] ?? false)
                    && $markerOverride !== []
                    && strtolower(trim((string) ($markerOverride['mode'] ?? ''))) === 'image'
                ) {
                    $markerOverride['image_uri'] = $this->mapFilterImageStorageService->normalizePublicUrl(
                        baseUrl: $baseUrl,
                        key: $key,
                        rawImageUri: isset($markerOverride['image_uri']) && is_string($markerOverride['image_uri'])
                            ? $markerOverride['image_uri']
                            : null,
                    );
                    $normalizedFilter['marker_override'] = $markerOverride;
                }
            }

            $normalizedFilters[] = $normalizedFilter;
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
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
