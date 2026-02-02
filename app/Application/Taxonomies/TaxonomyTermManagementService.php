<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Illuminate\Validation\ValidationException;

class TaxonomyTermManagementService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $taxonomyId): array
    {
        $taxonomy = $this->findTaxonomy($taxonomyId);

        return TaxonomyTerm::query()
            ->where('taxonomy_id', (string) $taxonomy->_id)
            ->orderBy('slug')
            ->get()
            ->map(fn (TaxonomyTerm $term): array => $this->toPayload($term))
            ->all();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(string $taxonomyId, array $payload): array
    {
        $taxonomy = $this->findTaxonomy($taxonomyId);
        $slug = $this->normalizeSlug($payload['slug'] ?? '');

        if (TaxonomyTerm::query()
            ->where('taxonomy_id', (string) $taxonomy->_id)
            ->where('slug', $slug)
            ->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Term slug already exists in this taxonomy.'],
            ]);
        }

        $term = TaxonomyTerm::create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => $slug,
            'name' => trim((string) ($payload['name'] ?? '')),
        ]);

        return $this->toPayload($term);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $taxonomyId, string $termId, array $payload): array
    {
        $taxonomy = $this->findTaxonomy($taxonomyId);
        $term = TaxonomyTerm::query()
            ->where('_id', $termId)
            ->where('taxonomy_id', (string) $taxonomy->_id)
            ->first();

        if (! $term) {
            abort(404, 'Taxonomy term not found.');
        }

        if (array_key_exists('slug', $payload)) {
            $slug = $this->normalizeSlug($payload['slug'] ?? '');
            if ($slug !== (string) $term->slug) {
                if (TaxonomyTerm::query()
                    ->where('taxonomy_id', (string) $taxonomy->_id)
                    ->where('slug', $slug)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'slug' => ['Term slug already exists in this taxonomy.'],
                    ]);
                }
                $term->slug = $slug;
            }
        }

        if (array_key_exists('name', $payload)) {
            $term->name = trim((string) $payload['name']);
        }

        $term->save();

        return $this->toPayload($term);
    }

    public function delete(string $taxonomyId, string $termId): void
    {
        $taxonomy = $this->findTaxonomy($taxonomyId);
        $term = TaxonomyTerm::query()
            ->where('_id', $termId)
            ->where('taxonomy_id', (string) $taxonomy->_id)
            ->first();

        if (! $term) {
            abort(404, 'Taxonomy term not found.');
        }

        $term->delete();
    }

    private function findTaxonomy(string $taxonomyId): Taxonomy
    {
        $taxonomy = Taxonomy::query()->where('_id', $taxonomyId)->first();
        if (! $taxonomy) {
            abort(404, 'Taxonomy not found.');
        }

        return $taxonomy;
    }

    private function normalizeSlug(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(TaxonomyTerm $term): array
    {
        return [
            'id' => (string) $term->_id,
            'taxonomy_id' => (string) ($term->taxonomy_id ?? ''),
            'slug' => (string) ($term->slug ?? ''),
            'name' => (string) ($term->name ?? ''),
        ];
    }
}
