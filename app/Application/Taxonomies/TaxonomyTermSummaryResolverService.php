<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Illuminate\Support\Facades\Event as EventBus;

class TaxonomyTermSummaryResolverService
{
    /**
     * @var array<string, Taxonomy|null>
     */
    private array $taxonomyBySlugCache = [];

    /**
     * @var array<string, string|null>
     */
    private array $termNameByTaxonomyAndSlugCache = [];

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, array{type: string, value: string, name: string, taxonomy_name: string, label: string}>
     */
    public function resolve(array $terms): array
    {
        $normalized = [];
        $types = [];

        foreach ($terms as $term) {
            $term = $this->normalizeDocument($term);
            if ($term === []) {
                continue;
            }

            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'value' => $value,
                'existing_name' => $this->normalizeOptionalString($term['name'] ?? null),
                'existing_label' => $this->normalizeOptionalString($term['label'] ?? null),
                'existing_taxonomy_name' => $this->normalizeOptionalString($term['taxonomy_name'] ?? null),
            ];
            $types[$type] = true;
        }

        if ($normalized === []) {
            return [];
        }

        $taxonomies = $this->taxonomiesBySlug(array_keys($types));

        $valuesByTaxonomyId = [];
        foreach ($normalized as $term) {
            $taxonomy = $taxonomies[$term['type']] ?? null;
            if (! $taxonomy) {
                continue;
            }

            $taxonomyId = (string) $taxonomy->_id;
            $valuesByTaxonomyId[$taxonomyId] ??= [];
            $valuesByTaxonomyId[$taxonomyId][] = $term['value'];
        }

        foreach ($valuesByTaxonomyId as $taxonomyId => $values) {
            $this->cacheTermNames($taxonomyId, array_values(array_unique($values)));
        }

        return array_map(function (array $term) use ($taxonomies): array {
            $taxonomy = $taxonomies[$term['type']] ?? null;
            $taxonomyId = $taxonomy ? (string) $taxonomy->_id : null;
            $termName = $taxonomyId !== null
                ? $this->normalizeOptionalString(
                    $this->termNameByTaxonomyAndSlugCache["{$taxonomyId}:{$term['value']}"] ?? null
                )
                : null;
            $name = $termName
                ?? $term['existing_name']
                ?? $term['existing_label']
                ?? $term['value'];
            $taxonomyName = $this->normalizeOptionalString($taxonomy?->name ?? null)
                ?? $term['existing_taxonomy_name']
                ?? $term['type'];

            return [
                'type' => $term['type'],
                'value' => $term['value'],
                'name' => $name,
                'taxonomy_name' => $taxonomyName,
                'label' => $name,
            ];
        }, $normalized);
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<string, Taxonomy|null>
     */
    private function taxonomiesBySlug(array $slugs): array
    {
        $normalizedSlugs = array_values(array_unique(array_filter(array_map(
            static fn (mixed $slug): string => trim((string) $slug),
            $slugs
        ), static fn (string $slug): bool => $slug !== '')));

        $missingSlugs = array_values(array_filter(
            $normalizedSlugs,
            fn (string $slug): bool => ! array_key_exists($slug, $this->taxonomyBySlugCache)
        ));

        if ($missingSlugs !== []) {
            EventBus::dispatch('belluga.taxonomy.summary_resolver_taxonomy_query', [$missingSlugs]);
            foreach ($missingSlugs as $slug) {
                $this->taxonomyBySlugCache[$slug] = null;
            }

            $rows = Taxonomy::query()
                ->whereIn('slug', $missingSlugs)
                ->get();

            foreach ($rows as $taxonomy) {
                $this->taxonomyBySlugCache[(string) $taxonomy->slug] = $taxonomy;
            }
        }

        return array_intersect_key(
            $this->taxonomyBySlugCache,
            array_fill_keys($normalizedSlugs, true)
        );
    }

    /**
     * @param  array<int, string>  $values
     */
    private function cacheTermNames(string $taxonomyId, array $values): void
    {
        $normalizedValues = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        ), static fn (string $value): bool => $value !== '')));

        $missingValues = array_values(array_filter(
            $normalizedValues,
            fn (string $value): bool => ! array_key_exists(
                "{$taxonomyId}:{$value}",
                $this->termNameByTaxonomyAndSlugCache
            )
        ));

        if ($missingValues === []) {
            return;
        }

        EventBus::dispatch('belluga.taxonomy.summary_resolver_terms_query', [$taxonomyId, $missingValues]);
        foreach ($missingValues as $value) {
            $this->termNameByTaxonomyAndSlugCache["{$taxonomyId}:{$value}"] = null;
        }

        $rows = TaxonomyTerm::query()
            ->where('taxonomy_id', $taxonomyId)
            ->whereIn('slug', $missingValues)
            ->get();

        foreach ($rows as $row) {
            $key = "{$taxonomyId}:{$row->slug}";
            $this->termNameByTaxonomyAndSlugCache[$key] = $this->normalizeOptionalString($row->name ?? null)
                ?? $this->normalizeOptionalString($row->slug ?? null)
                ?? '';
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, array{type: string, value: string, name: string, taxonomy_name: string, label: string}>
     */
    public function ensureSnapshots(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        if ($this->needsResolution($terms)) {
            return $this->resolve($terms);
        }

        $snapshots = [];
        foreach ($terms as $term) {
            $term = $this->normalizeDocument($term);
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            $name = $this->normalizeOptionalString($term['name'] ?? null);
            $taxonomyName = $this->normalizeOptionalString($term['taxonomy_name'] ?? null);

            if ($type === '' || $value === '' || $name === null || $taxonomyName === null) {
                continue;
            }

            $snapshots[] = [
                'type' => $type,
                'value' => $value,
                'name' => $name,
                'taxonomy_name' => $taxonomyName,
                'label' => $this->normalizeOptionalString($term['label'] ?? null) ?? $name,
            ];
        }

        return $snapshots;
    }

    /**
     * @param  array<int, mixed>  $terms
     */
    public function needsResolution(array $terms): bool
    {
        foreach ($terms as $term) {
            $term = $this->normalizeDocument($term);
            if ($term === []) {
                continue;
            }

            if (
                trim((string) ($term['type'] ?? '')) === ''
                || trim((string) ($term['value'] ?? '')) === ''
            ) {
                continue;
            }

            if (
                $this->normalizeOptionalString($term['name'] ?? null) === null
                || $this->normalizeOptionalString($term['taxonomy_name'] ?? null) === null
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDocument(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
