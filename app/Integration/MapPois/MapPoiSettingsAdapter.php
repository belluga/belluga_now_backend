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
        $canonicalFilters = $this->canonicalMapFilters($settings?->getAttribute('discovery_filters'));
        if ($canonicalFilters !== []) {
            $mapUi['filters'] = $canonicalFilters;
        }
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function canonicalMapFilters(mixed $discoveryFilters): array
    {
        $settings = $this->normalizeDocument($discoveryFilters);
        $surfaces = $this->normalizeDocument($settings['surfaces'] ?? null);
        $surface = $this->normalizeDocument($surfaces['public_map.primary'] ?? null);
        $filters = $this->normalizeList($surface['filters'] ?? null);
        if ($filters === []) {
            return [];
        }

        $canonical = [];
        foreach ($filters as $filter) {
            $normalized = $this->normalizeDocument($filter);
            if ($normalized === []) {
                continue;
            }

            $key = strtolower(trim((string) ($normalized['key'] ?? '')));
            $label = trim((string) ($normalized['label'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }

            $canonical[] = [
                'key' => $key,
                'label' => $label,
                ...($this->normalizeOptionalString($normalized['image_uri'] ?? null) !== null
                    ? ['image_uri' => $this->normalizeOptionalString($normalized['image_uri'] ?? null)]
                    : []),
                'override_marker' => (bool) ($normalized['override_marker'] ?? false),
                ...($this->normalizeDocument($normalized['marker_override'] ?? null) !== []
                    ? ['marker_override' => $this->normalizeDocument($normalized['marker_override'] ?? null)]
                    : []),
                'query' => $this->canonicalMapFilterQuery(
                    $this->normalizeDocument($normalized['query'] ?? null)
                ),
            ];
        }

        return $canonical;
    }

    /**
     * @return array{source: string|null, types: array<int, string>, taxonomy: array<int, string>, tags: array<int, string>, categories: array<int, string>}
     */
    private function canonicalMapFilterQuery(array $query): array
    {
        $entities = $this->normalizeStringList($query['entities'] ?? null);
        $typesByEntity = $this->normalizeDocument($query['types_by_entity'] ?? null);
        $taxonomyByGroup = $this->normalizeDocument($query['taxonomy'] ?? null);

        return [
            'source' => count($entities) === 1 ? $this->mapEntityToSource($entities[0]) : null,
            'types' => $this->flattenTypesByEntity($entities, $typesByEntity),
            'taxonomy' => $this->flattenTaxonomyByGroup($taxonomyByGroup),
            'tags' => $this->normalizeStringList($query['tags'] ?? null),
            'categories' => $this->normalizeStringList($query['category_keys'] ?? ($query['categories'] ?? null)),
        ];
    }

    private function mapEntityToSource(string $entity): ?string
    {
        return match (strtolower(trim($entity))) {
            'event' => 'event',
            'account_profile' => 'account_profile',
            'static_asset' => 'static_asset',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $entities
     * @param  array<string, mixed>  $typesByEntity
     * @return array<int, string>
     */
    private function flattenTypesByEntity(array $entities, array $typesByEntity): array
    {
        $types = [];
        foreach ($entities as $entity) {
            foreach ($this->normalizeStringList($typesByEntity[$entity] ?? null) as $type) {
                $types[$type] = $type;
            }
        }

        return array_values($types);
    }

    /**
     * @param  array<string, mixed>  $taxonomyByGroup
     * @return array<int, string>
     */
    private function flattenTaxonomyByGroup(array $taxonomyByGroup): array
    {
        $tokens = [];
        foreach ($taxonomyByGroup as $taxonomy => $values) {
            $taxonomyKey = strtolower(trim((string) $taxonomy));
            if ($taxonomyKey === '') {
                continue;
            }
            foreach ($this->normalizeStringList($values) as $value) {
                $tokens["{$taxonomyKey}:{$value}"] = "{$taxonomyKey}:{$value}";
            }
        }

        return array_values($tokens);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = is_string($value) ? [$value] : $this->normalizeList($value);
        $normalized = [];
        foreach ($items as $item) {
            $token = strtolower(trim((string) $item));
            if ($token !== '') {
                $normalized[$token] = $token;
            }
        }

        return array_values($normalized);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
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
