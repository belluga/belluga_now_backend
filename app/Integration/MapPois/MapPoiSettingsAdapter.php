<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\Media\MapFilterImageStorageService;
use App\Models\Tenants\TenantSettings;
use Belluga\DiscoveryFilters\Data\DiscoveryFilterDefinition;
use Belluga\DiscoveryFilters\Services\DiscoveryFilterCatalogService;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use MongoDB\Model\BSONDocument;

class MapPoiSettingsAdapter implements MapPoiSettingsContract
{
    public function __construct(
        private readonly MapFilterImageStorageService $mapFilterImageStorageService,
        private readonly DiscoveryFilterCatalogService $discoveryFilterCatalogService,
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

        $canonicalMapFilters = $this->mapCanonicalFiltersForPublicMap();
        if ($canonicalMapFilters !== []) {
            $mapUi['filters'] = $canonicalMapFilters;
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapCanonicalFiltersForPublicMap(): array
    {
        $definitions = $this->discoveryFilterCatalogService->surfaceDefinitions('public_map.primary');
        if ($definitions === []) {
            return [];
        }

        $filters = [];
        foreach ($definitions as $definition) {
            if ($definition->target !== 'map_poi') {
                continue;
            }

            $filter = [
                'key' => $definition->key,
                'label' => $definition->label,
                'override_marker' => $definition->overrideMarker,
                'query' => $this->mapCanonicalDefinitionToMapQuery($definition),
            ];

            if ($definition->imageUri !== null) {
                $filter['image_uri'] = $definition->imageUri;
            }
            if ($definition->markerOverride !== null) {
                $filter['marker_override'] = $definition->markerOverride;
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCanonicalDefinitionToMapQuery(DiscoveryFilterDefinition $definition): array
    {
        $query = [];
        $source = $this->mapCanonicalEntityToMapSource($definition->entities[0] ?? null);
        if ($source !== null) {
            $query['source'] = $source;
        }

        $types = [];
        foreach ($definition->typesByEntity as $entityTypes) {
            foreach ($entityTypes as $type) {
                $candidate = strtolower(trim((string) $type));
                if ($candidate !== '') {
                    $types[$candidate] = true;
                }
            }
        }
        if ($types !== []) {
            $query['types'] = array_keys($types);
        }

        $taxonomy = [];
        foreach ($definition->taxonomyValuesByGroup as $group => $values) {
            $groupKey = strtolower(trim((string) $group));
            foreach ($values as $value) {
                $valueKey = strtolower(trim((string) $value));
                if ($valueKey === '') {
                    continue;
                }
                $taxonomy[] = str_contains($valueKey, ':') || $groupKey === ''
                    ? $valueKey
                    : "{$groupKey}:{$valueKey}";
            }
        }
        $taxonomy = array_values(array_unique($taxonomy));
        if ($taxonomy !== []) {
            $query['taxonomy'] = $taxonomy;
        }

        return $query;
    }

    private function mapCanonicalEntityToMapSource(?string $entity): ?string
    {
        return match (strtolower(trim((string) $entity))) {
            'event' => 'event',
            'account_profile' => 'account_profile',
            'static_asset' => 'static',
            default => null,
        };
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
