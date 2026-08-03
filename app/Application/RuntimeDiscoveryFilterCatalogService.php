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
     * @param  array{primary?: mixed, taxonomy?: mixed}  $selection
     * @param  array<string, mixed>  $catalog
     * @return array{primary: array<int, string>, taxonomy: array<string, array<int, string>>, changed: bool}
     */
    public function repairSelectionAgainstCanonicalCatalog(array $selection, array $catalog): array
    {
        $allowedPrimaryKeys = [];
        foreach ($this->normalizeList($catalog['filters'] ?? []) as $filter) {
            $key = strtolower(trim((string) ($filter['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $allowedPrimaryKeys[$key] = $key;
        }

        $rawPrimary = $this->normalizeStringList($selection['primary'] ?? []);
        $primary = [];
        foreach ($rawPrimary as $key) {
            if (! isset($allowedPrimaryKeys[$key])) {
                continue;
            }
            $primary[] = $key;
        }
        $taxonomyOptionsByKey = [];
        foreach ($this->normalizeMap($catalog['taxonomy_options'] ?? []) as $key => $option) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }
            $taxonomyOptionsByKey[$normalizedKey] = $this->normalizeMap($option);
        }

        $rawTaxonomy = $this->normalizeStringListMap($selection['taxonomy'] ?? []);
        $taxonomy = [];
        foreach ($rawTaxonomy as $group => $values) {
            if (! isset($taxonomyOptionsByKey[$group])) {
                continue;
            }

            $option = $taxonomyOptionsByKey[$group];
            $allowedTerms = null;
            if (! ((bool) ($option['terms_truncated'] ?? false))) {
                $allowedTerms = [];
                foreach ($this->normalizeList($option['terms'] ?? []) as $term) {
                    $value = strtolower(trim((string) ($term['value'] ?? '')));
                    if ($value === '') {
                        continue;
                    }
                    $allowedTerms[$value] = $value;
                }
            }

            $nextValues = [];
            foreach ($values as $value) {
                if ($allowedTerms !== null && ! isset($allowedTerms[$value])) {
                    continue;
                }
                $nextValues[] = $value;
            }

            if ($nextValues !== []) {
                $taxonomy[$group] = $nextValues;
            }
        }

        return [
            'primary' => $primary,
            'taxonomy' => $taxonomy,
            'changed' => $rawPrimary !== $primary
                || ! $this->sameStringListMap($rawTaxonomy, $taxonomy),
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

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $raw = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($raw as $item) {
            $value = strtolower(trim((string) $item));
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function normalizeStringListMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $items) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }
            $normalizedValues = $this->normalizeStringList($items);
            if ($normalizedValues === []) {
                continue;
            }
            $normalized[$normalizedKey] = $normalizedValues;
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<int, string>>  $left
     * @param  array<string, array<int, string>>  $right
     */
    private function sameStringListMap(array $left, array $right): bool
    {
        if (array_keys($left) !== array_keys($right)) {
            return false;
        }

        foreach ($left as $key => $values) {
            if (($right[$key] ?? []) !== $values) {
                return false;
            }
        }

        return true;
    }
}
