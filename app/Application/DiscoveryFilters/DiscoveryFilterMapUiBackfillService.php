<?php

declare(strict_types=1);

namespace App\Application\DiscoveryFilters;

use App\Models\Tenants\TenantSettings;

final class DiscoveryFilterMapUiBackfillService
{
    /**
     * @return array{status: string, migrated: int, skipped_reason?: string}
     */
    public function backfillCurrentTenant(bool $force = false): array
    {
        $settings = TenantSettings::current();
        if (! $settings) {
            return [
                'status' => 'skipped',
                'migrated' => 0,
                'skipped_reason' => 'missing_settings_document',
            ];
        }

        $mapUi = $this->normalizeMap($settings->getAttribute('map_ui'));
        $legacyFilters = $this->normalizeList($mapUi['filters'] ?? null);
        if ($legacyFilters === []) {
            return [
                'status' => 'skipped',
                'migrated' => 0,
                'skipped_reason' => 'missing_legacy_map_filters',
            ];
        }

        $discoveryFilters = $this->normalizeMap($settings->getAttribute('discovery_filters'));
        $surfaces = $this->normalizeMap($discoveryFilters['surfaces'] ?? []);
        $publicMap = $this->normalizeMap($surfaces['public_map.primary'] ?? []);
        $existing = $this->normalizeList($publicMap['filters'] ?? null);

        if ($existing !== [] && ! $force) {
            return [
                'status' => 'skipped',
                'migrated' => 0,
                'skipped_reason' => 'canonical_surface_already_configured',
            ];
        }

        $canonical = [];
        foreach ($legacyFilters as $legacyFilter) {
            $mapped = $this->mapLegacyFilter($this->normalizeMap($legacyFilter));
            if ($mapped !== null) {
                $canonical[] = $mapped;
            }
        }

        $surfaces['public_map.primary'] = [
            ...$publicMap,
            'primary_selection_mode' => 'single',
            'target' => 'map_poi',
            'filters' => $canonical,
        ];
        $discoveryFilters['surfaces'] = $surfaces;

        $settings->setAttribute('discovery_filters', $discoveryFilters);
        $settings->save();

        return [
            'status' => 'migrated',
            'migrated' => count($canonical),
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>|null
     */
    private function mapLegacyFilter(array $filter): ?array
    {
        $key = $this->normalizeToken($filter['key'] ?? null);
        $label = $this->normalizeLabel($filter['label'] ?? null);
        if ($key === '' || $label === '') {
            return null;
        }

        $query = $this->normalizeMap($filter['query'] ?? []);
        $source = $this->normalizeToken($query['source'] ?? null);
        $entity = $this->mapLegacySourceToEntity($source);
        $types = $this->normalizeStringList($query['types'] ?? []);
        $taxonomy = $this->mapLegacyTaxonomy($query['taxonomy'] ?? []);

        $canonical = [
            'key' => $key,
            'surface' => 'public_map.primary',
            'target' => 'map_poi',
            'label' => $label,
            'primary_selection_mode' => 'single',
            'override_marker' => (bool) ($filter['override_marker'] ?? false),
            'query' => [
                'entities' => $entity === null ? [] : [$entity],
                'types_by_entity' => $entity === null || $types === [] ? [] : [$entity => $types],
                'taxonomy' => $taxonomy,
            ],
        ];

        $imageUri = $this->normalizeNullableLabel($filter['image_uri'] ?? null);
        if ($imageUri !== null) {
            $canonical['image_uri'] = $imageUri;
        }

        $markerOverride = $this->normalizeMap($filter['marker_override'] ?? null);
        if ($markerOverride !== []) {
            $canonical['marker_override'] = $markerOverride;
        }

        return $canonical;
    }

    private function mapLegacySourceToEntity(string $source): ?string
    {
        return match ($source) {
            'event' => 'event',
            'account_profile', 'account' => 'account_profile',
            'static', 'static_asset', 'asset' => 'static_asset',
            default => null,
        };
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function mapLegacyTaxonomy(mixed $raw): array
    {
        $mapped = [];
        foreach ($this->normalizeStringList($raw) as $token) {
            if (! str_contains($token, ':')) {
                $mapped['legacy'][] = $token;
                continue;
            }

            [$group, $value] = explode(':', $token, 2);
            $group = $this->normalizeToken($group);
            $value = $this->normalizeToken($value);
            if ($group !== '' && $value !== '') {
                $mapped[$group][] = $value;
            }
        }

        foreach ($mapped as $group => $values) {
            $mapped[$group] = array_values(array_unique($values));
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $raw = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($raw as $entry) {
            $token = $this->normalizeToken($entry);
            if ($token !== '') {
                $normalized[] = $token;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeToken(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function normalizeLabel(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableLabel(mixed $value): ?string
    {
        $normalized = $this->normalizeLabel($value);

        return $normalized === '' ? null : $normalized;
    }
}
