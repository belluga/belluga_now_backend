<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;

class TaxonomyTermSummaryResolverService
{
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

        $taxonomies = Taxonomy::query()
            ->whereIn('slug', array_keys($types))
            ->get()
            ->keyBy(static fn (Taxonomy $taxonomy): string => (string) $taxonomy->slug);

        $valuesByTaxonomyId = [];
        foreach ($normalized as $term) {
            $taxonomy = $taxonomies->get($term['type']);
            if (! $taxonomy) {
                continue;
            }

            $taxonomyId = (string) $taxonomy->_id;
            $valuesByTaxonomyId[$taxonomyId] ??= [];
            $valuesByTaxonomyId[$taxonomyId][] = $term['value'];
        }

        $summaryMap = [];
        foreach ($valuesByTaxonomyId as $taxonomyId => $values) {
            $rows = TaxonomyTerm::query()
                ->where('taxonomy_id', $taxonomyId)
                ->whereIn('slug', array_values(array_unique($values)))
                ->get();

            foreach ($rows as $row) {
                $summaryMap["{$taxonomyId}:{$row->slug}"] = $this->normalizeOptionalString($row->name ?? null)
                    ?? $this->normalizeOptionalString($row->slug ?? null)
                    ?? '';
            }
        }

        return array_map(function (array $term) use ($taxonomies, $summaryMap): array {
            $taxonomy = $taxonomies->get($term['type']);
            $taxonomyId = $taxonomy ? (string) $taxonomy->_id : null;
            $termName = $taxonomyId !== null
                ? $this->normalizeOptionalString($summaryMap["{$taxonomyId}:{$term['value']}"] ?? null)
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
