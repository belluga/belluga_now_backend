<?php

declare(strict_types=1);

namespace App\Integration\DiscoveryFilters\Concerns;

use App\Models\Tenants\Taxonomy;

trait FormatsDiscoveryFilterTypeOptions
{
    /**
     * @param  array<int, string>  $allowedTaxonomies
     * @return array<int, array{slug: string, label: string}>
     */
    private function taxonomyOptions(array $allowedTaxonomies): array
    {
        if ($allowedTaxonomies === []) {
            return [];
        }

        return Taxonomy::query()
            ->whereIn('slug', array_values(array_unique($allowedTaxonomies)))
            ->orderBy('name')
            ->get()
            ->map(static fn (Taxonomy $taxonomy): array => [
                'slug' => (string) ($taxonomy->slug ?? ''),
                'label' => (string) ($taxonomy->name ?? $taxonomy->slug ?? ''),
            ])
            ->filter(static fn (array $item): bool => trim($item['slug']) !== '' && trim($item['label']) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $raw = is_array($value) ? $value : [];
        $normalized = [];
        foreach ($raw as $entry) {
            $candidate = strtolower(trim((string) $entry));
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeVisual(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return $value;
    }
}
