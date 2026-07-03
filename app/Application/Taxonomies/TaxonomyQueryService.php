<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;

class TaxonomyQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $query = Taxonomy::query()->orderBy('slug');

        $slugs = $this->normalizeStringList($filters['slugs'] ?? []);
        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        }

        $appliesTo = trim((string) ($filters['applies_to'] ?? ''));
        if ($appliesTo !== '') {
            $query->where('applies_to', $appliesTo);
        }

        return $query
            ->get()
            ->map(fn (Taxonomy $taxonomy): array => $this->toPayload($taxonomy))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $taxonomyId): array
    {
        $taxonomy = Taxonomy::query()->where('_id', $taxonomyId)->first();
        if (! $taxonomy) {
            abort(404, 'Taxonomy not found.');
        }

        return $this->toPayload($taxonomy);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Taxonomy $taxonomy): array
    {
        return [
            'id' => (string) $taxonomy->_id,
            'slug' => (string) ($taxonomy->slug ?? ''),
            'name' => (string) ($taxonomy->name ?? ''),
            'applies_to' => array_values(array_filter(
                is_array($taxonomy->applies_to ?? null) ? $taxonomy->applies_to : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
            'icon' => $taxonomy->icon ?? null,
            'color' => $taxonomy->color ?? null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $rawValues = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_values(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), $rawValues),
            static fn (string $entry): bool => $entry !== ''
        ))));
    }
}
