<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\DiscoveryFilters\DiscoveryFilterPublicCatalogService;

final class RuntimeDiscoveryFilterCatalogService
{
    public function __construct(
        private readonly DiscoveryFilterPublicCatalogService $publicCatalogService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $runtimeFacets
     * @return array<string, mixed>
     */
    public function buildCanonicalCatalog(
        string $surface,
        ?array $runtimeFacets,
        ?string $baseUrl = null,
    ): array {
        $baseline = $this->publicCatalogService->catalogForSurface($surface, $baseUrl);
        if ($runtimeFacets === null) {
            return $baseline;
        }

        $allowedKeys = $this->normalizeStringSet($runtimeFacets['filter_keys'] ?? []);
        $filters = array_values(array_filter(
            $this->normalizeList($baseline['filters'] ?? []),
            fn (array $filter): bool => in_array(
                strtolower(trim((string) ($filter['key'] ?? ''))),
                $allowedKeys,
                true
            )
        ));

        $typeOptions = [];
        foreach ($this->normalizeMap($baseline['type_options'] ?? []) as $entity => $options) {
            $filtered = array_values(array_filter(
                $this->normalizeList($options),
                fn (array $option): bool => in_array(
                    strtolower(trim((string) ($option['value'] ?? ''))),
                    $allowedKeys,
                    true
                )
            ));
            if ($filtered !== []) {
                $typeOptions[$entity] = $filtered;
            }
        }

        $baselineTaxonomyOptions = $this->normalizeMap($baseline['taxonomy_options'] ?? []);
        $taxonomyOptions = [];
        foreach ($this->normalizeMap($runtimeFacets['taxonomy_options'] ?? []) as $key => $rawOption) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            $runtimeOption = $this->normalizeMap($rawOption);
            $baselineOption = $this->normalizeMap($baselineTaxonomyOptions[$normalizedKey] ?? []);
            $terms = array_values(array_filter(
                $this->normalizeList($runtimeOption['terms'] ?? []),
                static fn (array $term): bool => trim((string) ($term['value'] ?? '')) !== ''
                    && trim((string) ($term['label'] ?? '')) !== ''
            ));
            if ($terms === []) {
                continue;
            }

            $taxonomyOptions[$normalizedKey] = [
                'key' => $normalizedKey,
                'label' => trim((string) (
                    $baselineOption['label']
                    ?? $runtimeOption['label']
                    ?? $normalizedKey
                )),
                'terms' => $terms,
                'terms_truncated' => (bool) ($runtimeOption['terms_truncated'] ?? false),
                'terms_limit' => (int) ($runtimeOption['terms_limit'] ?? count($terms)),
            ];
        }

        return [
            'surface' => trim((string) ($baseline['surface'] ?? $surface)),
            'filters' => $filters,
            'type_options' => $typeOptions,
            'taxonomy_options' => $taxonomyOptions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized[] = $this->normalizeMap($item);
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringSet(mixed $value): array
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return $normalized === '' ? [] : [$normalized];
        }

        if (! is_iterable($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            $normalized = strtolower(trim((string) $item));
            if ($normalized === '') {
                continue;
            }
            $values[$normalized] = $normalized;
        }

        return array_values($values);
    }
}
