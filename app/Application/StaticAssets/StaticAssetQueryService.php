<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\Shared\Query\AbstractQueryService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\StaticAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use MongoDB\BSON\ObjectId;

class StaticAssetQueryService extends AbstractQueryService
{
    public function __construct(
        private readonly StaticAssetMediaService $mediaService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = StaticAsset::query();
        $searchQuery = $this->extractSearchQuery($queryParams);
        if ($searchQuery !== null) {
            $this->applySearchFilter($query, $searchQuery);
        }

        return $this->buildPaginator($query, $this->withoutSearch($queryParams), $includeArchived, $perPage)
            ->through(function (StaticAsset $asset): array {
                return $this->format($asset);
            });
    }

    public function findOrFail(string $assetId, bool $onlyTrashed = false): StaticAsset
    {
        $query = $onlyTrashed ? StaticAsset::onlyTrashed() : StaticAsset::query();
        $asset = $query->find($assetId);

        if (! $asset) {
            try {
                $asset = $query->where('_id', new ObjectId($assetId))->first();
            } catch (\Throwable) {
                $asset = null;
            }
        }

        if (! $asset) {
            throw (new ModelNotFoundException)->setModel(StaticAsset::class, [$assetId]);
        }

        return $asset;
    }

    public function findBySlugOrFail(string $slug): StaticAsset
    {
        $asset = StaticAsset::query()->where('slug', $slug)->first();

        if (! $asset) {
            throw (new ModelNotFoundException)->setModel(StaticAsset::class, [$slug]);
        }

        return $asset;
    }

    public function findByIdOrSlug(string $identifier): StaticAsset
    {
        try {
            return $this->findOrFail($identifier);
        } catch (ModelNotFoundException) {
            return $this->findBySlugOrFail($identifier);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function format(StaticAsset $asset): array
    {
        $baseUrl = request()->getSchemeAndHttpHost();

        return [
            'id' => (string) $asset->_id,
            'profile_type' => $asset->profile_type,
            'display_name' => $asset->display_name,
            'slug' => $asset->slug,
            'bio' => $asset->bio,
            'content' => $asset->content,
            'avatar_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $asset,
                'avatar',
                is_string($asset->avatar_url) ? $asset->avatar_url : null
            ),
            'cover_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $asset,
                'cover',
                is_string($asset->cover_url) ? $asset->cover_url : null
            ),
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->ensureSnapshots(
                is_array($asset->taxonomy_terms ?? null) ? $asset->taxonomy_terms : []
            ),
            'location' => $this->formatLocation($asset->location),
            'is_active' => (bool) ($asset->is_active ?? false),
            'created_at' => $asset->created_at?->toJSON(),
            'updated_at' => $asset->updated_at?->toJSON(),
            'deleted_at' => $asset->deleted_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }

    protected function baseSearchableFields(): array
    {
        return (new StaticAsset)->getFillable();
    }

    protected function stringFields(): array
    {
        return ['profile_type', 'display_name', 'slug'];
    }

    protected function arrayFields(): array
    {
        return [];
    }

    protected function dateFields(): array
    {
        return ['created_at', 'updated_at', 'deleted_at'];
    }

    private function applySearchFilter(\Illuminate\Database\Eloquent\Builder $query, string $searchQuery): void
    {
        $regex = $this->buildContainsRegexPattern($searchQuery);

        $query->whereRaw([
            '$or' => [
                ['display_name' => ['$regex' => $regex, '$options' => 'i']],
                ['slug' => ['$regex' => $regex, '$options' => 'i']],
                ['content' => ['$regex' => $regex, '$options' => 'i']],
                ['taxonomy_terms.value' => ['$regex' => $regex, '$options' => 'i']],
            ],
        ]);
    }

    private function buildContainsRegexPattern(string $searchQuery): string
    {
        $escaped = preg_quote(trim($searchQuery), '/');

        return $escaped;
    }

    private function extractSearchQuery(array $queryParams): ?string
    {
        $rawSearch = $queryParams['search'] ?? $queryParams['q'] ?? null;
        if (! is_string($rawSearch)) {
            return null;
        }
        $trimmed = trim($rawSearch);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutSearch(array $queryParams): array
    {
        unset($queryParams['search'], $queryParams['q']);
        Arr::forget($queryParams, 'filter.search');
        Arr::forget($queryParams, 'filter.q');

        return $queryParams;
    }
}
