<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;

class TaxonomyTermSummaryResolverService
{
    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, array{type: string, value: string, name: string}>
     */
    public function resolve(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $normalized = [];
        $types = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
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
                $summaryMap["{$taxonomyId}:{$row->slug}"] = trim((string) ($row->name ?? $row->slug ?? ''));
            }
        }

        return array_map(function (array $term) use ($taxonomies, $summaryMap): array {
            $taxonomy = $taxonomies->get($term['type']);
            $taxonomyId = $taxonomy ? (string) $taxonomy->_id : null;
            $name = $taxonomyId !== null
                ? ($summaryMap["{$taxonomyId}:{$term['value']}"] ?? $term['value'])
                : $term['value'];

            return [
                'type' => $term['type'],
                'value' => $term['value'],
                'name' => $name,
            ];
        }, $normalized);
    }
}
