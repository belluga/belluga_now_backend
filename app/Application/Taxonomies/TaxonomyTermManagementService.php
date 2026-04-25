<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Jobs\Taxonomies\RepairTaxonomyTermSnapshotsJob;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Support\Validation\InputConstraints;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
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
     * @param  array<int, mixed>  $taxonomyIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listBatch(array $taxonomyIds, ?int $termLimit = null, ?int $maxTermLimit = null): array
    {
        $ids = collect($taxonomyIds)
            ->map(fn ($taxonomyId): string => trim((string) $taxonomyId))
            ->filter(static fn (string $taxonomyId): bool => $taxonomyId !== '')
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $existingIds = Taxonomy::query()
            ->whereIn('_id', $ids)
            ->get(['_id'])
            ->map(fn (Taxonomy $taxonomy): string => (string) $taxonomy->_id)
            ->values()
            ->all();

        if (count($existingIds) !== count($ids)) {
            abort(404, 'Taxonomy not found.');
        }

        $payload = array_fill_keys($ids, []);
        $effectiveMax = max(1, $maxTermLimit ?? InputConstraints::ADMIN_TAXONOMY_BATCH_TERMS_PER_GROUP_MAX);
        $effectiveLimit = max(1, min($termLimit ?? $effectiveMax, $effectiveMax));

        $rows = TaxonomyTerm::raw(
            static fn ($collection) => $collection->aggregate([
                [
                    '$match' => [
                        'taxonomy_id' => ['$in' => $ids],
                    ],
                ],
                [
                    '$group' => [
                        '_id' => '$taxonomy_id',
                        'terms' => [
                            '$topN' => [
                                'n' => $effectiveLimit,
                                'sortBy' => ['slug' => 1, '_id' => 1],
                                'output' => '$$ROOT',
                            ],
                        ],
                    ],
                ],
            ])
        );

        foreach ($rows as $row) {
            $rowPayload = $this->normalizeDocument($row);
            $taxonomyId = trim((string) ($rowPayload['_id'] ?? $rowPayload['id'] ?? ''));
            if ($taxonomyId === '' || ! array_key_exists($taxonomyId, $payload)) {
                continue;
            }
            $terms = $rowPayload['terms'] ?? [];
            if (! is_iterable($terms)) {
                continue;
            }
            $payload[$taxonomyId] = collect($terms)
                ->map(fn (mixed $term): array => $this->toPayloadFromRaw($term))
                ->filter(static fn (array $term): bool => ($term['id'] ?? '') !== '')
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $payload
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
                throw ValidationException::withMessages([
                    'slug' => ['Term slug cannot be changed after creation. Use an explicit migration workflow.'],
                ]);
            }
        }

        $previousName = (string) ($term->name ?? '');
        if (array_key_exists('name', $payload)) {
            $term->name = trim((string) $payload['name']);
        }

        $term->save();

        if ((string) ($term->name ?? '') !== $previousName) {
            DB::connection('tenant')->afterCommit(
                static fn () => RepairTaxonomyTermSnapshotsJob::dispatch(
                    (string) ($taxonomy->slug ?? ''),
                    (string) ($term->slug ?? '')
                )
            );
        }

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

    /**
     * @return array<string, mixed>
     */
    private function toPayloadFromRaw(mixed $raw): array
    {
        $term = $this->normalizeDocument($raw);

        return [
            'id' => (string) ($term['_id'] ?? $term['id'] ?? ''),
            'taxonomy_id' => (string) ($term['taxonomy_id'] ?? ''),
            'slug' => (string) ($term['slug'] ?? ''),
            'name' => (string) ($term['name'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDocument(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            return $value->getArrayCopy();
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }
}
