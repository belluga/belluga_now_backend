<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;

class TaxonomyQueryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return Taxonomy::query()
            ->orderBy('slug')
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
}
