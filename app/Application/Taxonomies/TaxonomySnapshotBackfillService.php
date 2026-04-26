<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\StaticAsset;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class TaxonomySnapshotBackfillService
{
    private const FAILURE_SAMPLE_LIMIT = 10;

    /**
     * @var array<string, array{type: string, value: string, name: string, taxonomy_name: string, label: string}>
     */
    private array $termSnapshotCache = [];

    public function __construct(
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function repair(?string $taxonomyType = null, ?string $termValue = null): array
    {
        $this->termSnapshotCache = [];
        $taxonomyType = $this->normalizeOptionalString($taxonomyType);
        $termValue = $this->normalizeOptionalString($termValue);

        $collections = [
            'account_profiles' => $this->repairRootTaxonomyModel(AccountProfile::class, $taxonomyType, $termValue, true),
            'static_assets' => $this->repairRootTaxonomyModel(StaticAsset::class, $taxonomyType, $termValue),
            'events' => $this->repairEventLikeModel(Event::class, $taxonomyType, $termValue),
            'event_occurrences' => $this->repairEventLikeModel(EventOccurrence::class, $taxonomyType, $termValue),
            'map_pois' => $this->repairRootTaxonomyModel(MapPoi::class, $taxonomyType, $termValue, true),
        ];

        return [
            'scope' => [
                'taxonomy_type' => $taxonomyType,
                'term_value' => $termValue,
            ],
            'collections' => $collections,
            'totals' => [
                'scanned' => array_sum(array_column($collections, 'scanned')),
                'repaired' => array_sum(array_column($collections, 'repaired')),
                'skipped' => array_sum(array_column($collections, 'skipped')),
                'failed' => array_sum(array_column($collections, 'failed')),
            ],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{scanned: int, repaired: int, skipped: int, failed: int, failures: array<int, array<string, mixed>>}
     */
    private function repairRootTaxonomyModel(
        string $modelClass,
        ?string $taxonomyType,
        ?string $termValue,
        bool $refreshFlatTerms = false
    ): array {
        $summary = $this->emptySummary();
        $query = $modelClass::query();
        $this->applyRootTaxonomyQuery($query, $taxonomyType, $termValue);

        foreach ($query->cursor() as $model) {
            if (! $model instanceof Model) {
                continue;
            }

            $summary['scanned']++;

            try {
                $terms = $this->normalizeList($model->getAttribute('taxonomy_terms') ?? []);
                if (! $this->termsContainScope($terms, $taxonomyType, $termValue)) {
                    $summary['skipped']++;

                    continue;
                }

                $resolved = $this->resolveTermsWithCache($terms);
                $changed = ! $this->samePayload($terms, $resolved);
                $flatChanged = false;
                if ($refreshFlatTerms) {
                    $expectedFlatTerms = $this->flattenTaxonomyTerms($resolved);
                    $flatChanged = ! $this->sameFlatTerms(
                        $model->getAttribute('taxonomy_terms_flat') ?? [],
                        $expectedFlatTerms
                    );
                }

                if ($changed || $flatChanged) {
                    $model->setAttribute('taxonomy_terms', $resolved);
                    if ($refreshFlatTerms) {
                        $model->setAttribute('taxonomy_terms_flat', $expectedFlatTerms);
                    }
                    $model->save();
                    $summary['repaired']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $error) {
                $this->recordFailure($summary, $model, $error);
            }
        }

        return $summary;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{scanned: int, repaired: int, skipped: int, failed: int, failures: array<int, array<string, mixed>>}
     */
    private function repairEventLikeModel(string $modelClass, ?string $taxonomyType, ?string $termValue): array
    {
        $summary = $this->emptySummary();
        $query = $modelClass::query();
        $this->applyEventLikeTaxonomyQuery($query, $taxonomyType, $termValue);

        foreach ($query->cursor() as $model) {
            if (! $model instanceof Model) {
                continue;
            }

            $summary['scanned']++;

            try {
                $changed = false;

                $terms = $this->normalizeList($model->getAttribute('taxonomy_terms') ?? []);
                if ($this->termsContainScope($terms, $taxonomyType, $termValue)) {
                    $resolved = $this->resolveTermsWithCache($terms);
                    if (! $this->samePayload($terms, $resolved)) {
                        $model->setAttribute('taxonomy_terms', $resolved);
                        $changed = true;
                    }
                }

                foreach (['venue', 'place_ref'] as $attribute) {
                    [$payload, $attributeChanged] = $this->refreshPayloadTaxonomyTerms(
                        $this->normalizeDocument($model->getAttribute($attribute) ?? []),
                        $taxonomyType,
                        $termValue
                    );
                    if ($attributeChanged) {
                        $model->setAttribute($attribute, $payload);
                        $changed = true;
                    }
                }

                foreach (['event_parties', 'linked_account_profiles', 'artists'] as $attribute) {
                    [$items, $attributeChanged] = $this->refreshListPayloadTaxonomyTerms(
                        $this->normalizeList($model->getAttribute($attribute) ?? []),
                        $taxonomyType,
                        $termValue
                    );
                    if ($attributeChanged) {
                        $model->setAttribute($attribute, $items);
                        $changed = true;
                    }
                }

                if ($changed) {
                    $model->save();
                    $summary['repaired']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $error) {
                $this->recordFailure($summary, $model, $error);
            }
        }

        return $summary;
    }

    private function applyRootTaxonomyQuery(mixed $query, ?string $taxonomyType, ?string $termValue): void
    {
        if ($taxonomyType === null) {
            return;
        }

        $query->whereRaw([
            'taxonomy_terms' => [
                '$elemMatch' => $this->buildTermMatch($taxonomyType, $termValue),
            ],
        ]);
    }

    private function applyEventLikeTaxonomyQuery(mixed $query, ?string $taxonomyType, ?string $termValue): void
    {
        if ($taxonomyType === null) {
            return;
        }

        $match = $this->buildTermMatch($taxonomyType, $termValue);
        $query->whereRaw([
            '$or' => [
                ['taxonomy_terms' => ['$elemMatch' => $match]],
                ['venue.taxonomy_terms' => ['$elemMatch' => $match]],
                ['place_ref.taxonomy_terms' => ['$elemMatch' => $match]],
                ['event_parties.metadata.taxonomy_terms' => ['$elemMatch' => $match]],
                ['linked_account_profiles.taxonomy_terms' => ['$elemMatch' => $match]],
                ['artists.taxonomy_terms' => ['$elemMatch' => $match]],
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildTermMatch(string $taxonomyType, ?string $termValue): array
    {
        $match = ['type' => $taxonomyType];
        if ($termValue !== null) {
            $match['value'] = $termValue;
        }

        return $match;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function refreshPayloadTaxonomyTerms(array $payload, ?string $taxonomyType, ?string $termValue): array
    {
        $changed = false;

        if (array_key_exists('taxonomy_terms', $payload)) {
            $terms = $this->normalizeList($payload['taxonomy_terms']);
            if ($this->termsContainScope($terms, $taxonomyType, $termValue)) {
                $resolved = $this->resolveTermsWithCache($terms);
                if (! $this->samePayload($terms, $resolved)) {
                    $payload['taxonomy_terms'] = $resolved;
                    $changed = true;
                }
            }
        }

        if (array_key_exists('metadata', $payload)) {
            [$metadata, $metadataChanged] = $this->refreshPayloadTaxonomyTerms(
                $this->normalizeDocument($payload['metadata']),
                $taxonomyType,
                $termValue
            );
            if ($metadataChanged) {
                $payload['metadata'] = $metadata;
                $changed = true;
            }
        }

        return [$payload, $changed];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{0: array<int, mixed>, 1: bool}
     */
    private function refreshListPayloadTaxonomyTerms(array $items, ?string $taxonomyType, ?string $termValue): array
    {
        $changed = false;
        $resolvedItems = [];

        foreach ($items as $item) {
            [$resolved, $itemChanged] = $this->refreshPayloadTaxonomyTerms(
                $this->normalizeDocument($item),
                $taxonomyType,
                $termValue
            );
            $resolvedItems[] = $resolved;
            $changed = $changed || $itemChanged;
        }

        return [$resolvedItems, $changed];
    }

    /**
     * @param  array<int, mixed>  $terms
     */
    private function termsContainScope(array $terms, ?string $taxonomyType, ?string $termValue): bool
    {
        if ($terms === []) {
            return false;
        }

        if ($taxonomyType === null) {
            return true;
        }

        foreach ($terms as $term) {
            $term = $this->normalizeDocument($term);
            if (($term['type'] ?? null) !== $taxonomyType) {
                continue;
            }
            if ($termValue === null || ($term['value'] ?? null) === $termValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, array{type: string, value: string, name: string, taxonomy_name: string, label: string}>
     */
    private function resolveTermsWithCache(array $terms): array
    {
        $normalized = [];
        $missing = [];

        foreach ($terms as $term) {
            $term = $this->normalizeDocument($term);
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            $cacheKey = $this->termCacheKey($type, $value);
            $normalized[] = [
                'cache_key' => $cacheKey,
                'term' => $term,
            ];
            if (! array_key_exists($cacheKey, $this->termSnapshotCache)) {
                $missing[$cacheKey] = [
                    'type' => $type,
                    'value' => $value,
                    'name' => $term['name'] ?? null,
                    'label' => $term['label'] ?? null,
                    'taxonomy_name' => $term['taxonomy_name'] ?? null,
                ];
            }
        }

        if ($missing !== []) {
            foreach ($this->taxonomyTermSummaryResolver->resolve(array_values($missing)) as $resolved) {
                $cacheKey = $this->termCacheKey($resolved['type'], $resolved['value']);
                $this->termSnapshotCache[$cacheKey] = $resolved;
            }
        }

        $resolved = [];
        foreach ($normalized as $entry) {
            $cached = $this->termSnapshotCache[$entry['cache_key']] ?? null;
            if ($cached !== null) {
                $resolved[] = $cached;
            }
        }

        return $resolved;
    }

    private function termCacheKey(string $type, string $value): string
    {
        return "{$type}:{$value}";
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    private function flattenTaxonomyTerms(array $terms): array
    {
        $flat = [];
        foreach ($terms as $term) {
            $term = $this->normalizeDocument($term);
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type !== '' && $value !== '') {
                $flat[] = "{$type}:{$value}";
            }
        }

        return array_values(array_unique($flat));
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function sameFlatTerms(mixed $current, array $expected): bool
    {
        $currentFlat = [];
        foreach ($this->normalizeList($current) as $term) {
            if (! is_scalar($term) && $term !== null) {
                continue;
            }

            $normalized = trim((string) $term);
            if ($normalized !== '') {
                $currentFlat[] = $normalized;
            }
        }

        return array_values(array_unique($currentFlat)) === $expected;
    }

    /**
     * @return array{scanned: int, repaired: int, skipped: int, failed: int, failures: array<int, array<string, mixed>>}
     */
    private function emptySummary(): array
    {
        return [
            'scanned' => 0,
            'repaired' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failures' => [],
        ];
    }

    /**
     * @param  array{scanned: int, repaired: int, skipped: int, failed: int, failures: array<int, array<string, mixed>>}  $summary
     */
    private function recordFailure(array &$summary, Model $model, \Throwable $error): void
    {
        $summary['failed']++;

        $failure = [
            'model' => $model::class,
            'model_id' => (string) $model->getKey(),
            'message' => $error->getMessage(),
        ];

        if (count($summary['failures']) < self::FAILURE_SAMPLE_LIMIT) {
            $summary['failures'][] = $failure;
        }

        Log::warning('Taxonomy term snapshot repair failed for a document.', [
            'model' => $failure['model'],
            'model_id' => $failure['model_id'],
            'exception' => $error::class,
            'message' => $failure['message'],
        ]);
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
        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
    {
        $items = $this->normalizeDocument($value);

        return array_values($items);
    }

    private function samePayload(mixed $left, mixed $right): bool
    {
        return json_encode($this->normalizeForComparison($left), JSON_UNESCAPED_UNICODE)
            === json_encode($this->normalizeForComparison($right), JSON_UNESCAPED_UNICODE);
    }

    private function normalizeForComparison(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeForComparison($item);
        }

        return $normalized;
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
