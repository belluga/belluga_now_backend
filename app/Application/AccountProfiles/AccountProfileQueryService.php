<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Shared\Query\AbstractQueryService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;

class AccountProfileQueryService extends AbstractQueryService
{
    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = AccountProfile::query();

        $ownershipState = $this->extractOwnershipState($queryParams);
        if ($ownershipState !== null) {
            $this->applyOwnershipFilter($query, $ownershipState);
        }

        $paginator = $this->buildPaginator(
            $query,
            $this->withoutOwnershipState($queryParams),
            $includeArchived,
            $perPage
        );

        return $this->hydrateOwnershipState($paginator);
    }

    public function publicPaginate(array $queryParams, int $perPage = 15): LengthAwarePaginator
    {
        $allowedTypes = $this->favoritableProfileTypes();
        $effectiveTypes = $this->resolveEffectivePublicProfileTypes($queryParams, $allowedTypes);

        $query = $this->withoutPublicProfileTypeFilters($queryParams);
        $taxonomyFilters = $this->resolvePublicTaxonomyFilters($query);
        $query = $this->withoutPublicTaxonomyFilters($query);
        $search = trim((string) ($query['search'] ?? ''));
        unset($query['search']);

        $baseQuery = AccountProfile::query()
            ->where('is_active', true);
        $this->applyPublicVisibilityConstraint($baseQuery);

        if ($effectiveTypes === []) {
            $baseQuery->whereRaw(['_id' => ['$exists' => false]]);
        } else {
            $baseQuery->whereIn('profile_type', $effectiveTypes);
        }

        $this->applyPublicTaxonomyFilter($baseQuery, $taxonomyFilters);
        $this->applyPublicSearchFilter($baseQuery, $search);
        $paginator = $this->buildPaginator(
            $baseQuery,
            $query,
            false,
            $perPage
        );

        return $this->hydrateOwnershipState($paginator);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    public function publicNear(array $queryParams): array
    {
        $allowedTypes = $this->nearEligibleProfileTypes();
        $effectiveTypes = $this->resolveEffectivePublicProfileTypes($queryParams, $allowedTypes);
        $taxonomyFilters = $this->resolvePublicTaxonomyFilters($queryParams);
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $pageSize = (int) ($queryParams['page_size'] ?? 10);
        if ($pageSize <= 0) {
            $pageSize = 10;
        }
        if ($pageSize > 50) {
            $pageSize = 50;
        }

        if ($effectiveTypes === []) {
            return [
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => false,
                'data' => [],
            ];
        }

        $originLat = $this->toFloat($queryParams['origin_lat'] ?? null);
        $originLng = $this->toFloat($queryParams['origin_lng'] ?? null);
        if ($originLat === null || $originLng === null) {
            return [
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => false,
                'data' => [],
            ];
        }

        $search = trim((string) ($queryParams['search'] ?? ''));
        $baseMatch = [
            '$and' => [
                ['is_active' => true],
                ['deleted_at' => null],
                ['profile_type' => ['$in' => $effectiveTypes]],
                ['location' => ['$ne' => null]],
                $this->publicVisibilityConstraintExpression(),
            ],
        ];
        if ($search !== '') {
            $baseMatch['$and'][] = $this->publicSearchExpression($search);
        }
        $taxonomyExpression = $this->publicTaxonomyExpression($taxonomyFilters);
        if ($taxonomyExpression !== []) {
            $baseMatch['$and'][] = $taxonomyExpression;
        }

        $geoNear = [
            'near' => [
                'type' => 'Point',
                'coordinates' => [$originLng, $originLat],
            ],
            'distanceField' => 'distance_meters',
            'spherical' => true,
            'query' => $baseMatch,
        ];
        $maxDistance = $this->toFloat($queryParams['max_distance_meters'] ?? null);
        if ($maxDistance !== null) {
            $geoNear['maxDistance'] = $maxDistance;
        }

        $skip = ($page - 1) * $pageSize;
        $limit = $pageSize + 1;

        $pipeline = [
            ['$geoNear' => $geoNear],
            ['$sort' => ['distance_meters' => 1, '_id' => 1]],
            ['$skip' => $skip],
            ['$limit' => $limit],
            ['$project' => ['_id' => 1, 'distance_meters' => 1]],
        ];

        $rows = AccountProfile::raw(fn ($collection) => $collection->aggregate($pipeline));
        $orderedIds = [];
        $distanceById = [];
        foreach ($rows as $row) {
            $payload = $this->normalizeDocument($row);
            $id = $this->resolveAggregateRowId($payload);
            if ($id === null) {
                continue;
            }

            $orderedIds[] = $id;
            $distanceById[$id] = isset($payload['distance_meters']) ? (float) $payload['distance_meters'] : null;
        }

        $hasMore = count($orderedIds) > $pageSize;
        if ($hasMore) {
            $orderedIds = array_slice($orderedIds, 0, $pageSize);
        }

        if ($orderedIds === []) {
            return [
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => false,
                'data' => [],
            ];
        }

        $profiles = AccountProfile::query()
            ->whereIn('_id', $orderedIds)
            ->get();
        $profilesById = [];
        foreach ($profiles as $profile) {
            $profilesById[(string) $profile->getKey()] = $profile;
        }

        /** @var Collection<int, AccountProfile> $orderedProfiles */
        $orderedProfiles = collect($orderedIds)
            ->map(static fn (string $id): ?AccountProfile => $profilesById[$id] ?? null)
            ->filter(static fn ($item): bool => $item instanceof AccountProfile)
            ->values();
        $accountsById = $this->loadAccountsById($orderedProfiles);
        $userOperatedLookup = $this->ownershipStateService->userOperatedAccountIdLookup(
            array_keys($accountsById)
        );

        $data = $orderedProfiles
            ->map(function (AccountProfile $profile) use ($accountsById, $userOperatedLookup, $distanceById): array {
                $id = (string) $profile->getKey();
                $payload = $this->format(
                    $profile,
                    $accountsById[(string) $profile->account_id] ?? null,
                    $userOperatedLookup
                );
                $payload['distance_meters'] = $distanceById[$id] ?? null;

                return $payload;
            })
            ->values()
            ->all();

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => $hasMore,
            'data' => $data,
        ];
    }

    public function publicFindBySlugOrFail(string $slug): AccountProfile
    {
        $normalizedSlug = trim($slug);
        $allowedTypes = $this->favoritableProfileTypes();

        if ($normalizedSlug === '' || $allowedTypes === []) {
            throw (new ModelNotFoundException)->setModel(AccountProfile::class, [$slug]);
        }

        $query = AccountProfile::query()
            ->where('slug', $normalizedSlug)
            ->where('is_active', true)
            ->whereIn('profile_type', $allowedTypes);
        $this->applyPublicVisibilityConstraint($query);

        $profile = $query->first();
        if (! $profile) {
            throw (new ModelNotFoundException)->setModel(AccountProfile::class, [$normalizedSlug]);
        }

        return $profile;
    }

    public function findOrFail(string $profileId, bool $onlyTrashed = false): AccountProfile
    {
        $query = $onlyTrashed ? AccountProfile::onlyTrashed() : AccountProfile::query();
        $profile = $query->find($profileId);

        if (! $profile) {
            try {
                $profile = $query->where('_id', new ObjectId($profileId))->first();
            } catch (\Throwable) {
                $profile = null;
            }
        }

        if (! $profile) {
            throw (new ModelNotFoundException)->setModel(AccountProfile::class, [$profileId]);
        }

        return $profile;
    }

    /**
     * @param  array<string, bool>  $userOperatedLookup
     * @return array<string, mixed>
     */
    private function format(
        AccountProfile $profile,
        ?Account $account = null,
        array $userOperatedLookup = []
    ): array {
        $baseUrl = request()->getSchemeAndHttpHost();
        $resolvedAccount = $account
            ?? Account::query()->where('_id', $profile->account_id)->first();

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'avatar_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $profile,
                'avatar',
                is_string($profile->avatar_url) ? $profile->avatar_url : null
            ),
            'cover_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $profile,
                'cover',
                is_string($profile->cover_url) ? $profile->cover_url : null
            ),
            'bio' => $profile->bio,
            'content' => $profile->content,
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->ensureSnapshots(
                is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : []
            ),
            'location' => $this->formatLocation($profile->location),
            'ownership_state' => $resolvedAccount
                ? $this->ownershipStateService->deriveOwnershipState(
                    $resolvedAccount,
                    $userOperatedLookup
                )
                : null,
            'created_at' => $profile->created_at?->toJSON(),
            'updated_at' => $profile->updated_at?->toJSON(),
            'deleted_at' => $profile->deleted_at?->toJSON(),
        ];
    }

    /**
     * @param  Collection<int, AccountProfile>  $profiles
     * @return array<string, Account>
     */
    private function loadAccountsById(Collection $profiles): array
    {
        $accountIds = $profiles
            ->map(static fn (AccountProfile $profile): string => (string) $profile->account_id)
            ->filter(static fn (string $id): bool => trim($id) !== '')
            ->unique()
            ->values()
            ->all();
        if ($accountIds === []) {
            return [];
        }

        $accounts = Account::query()
            ->whereIn('_id', $accountIds)
            ->get();

        $byId = [];
        foreach ($accounts as $account) {
            $byId[(string) $account->getKey()] = $account;
        }

        return $byId;
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

    private function applyOwnershipFilter(Builder $profileQuery, string $ownershipState): void
    {
        $accountQuery = Account::query();
        $this->ownershipStateService->applyOwnershipFilterToAccountsQuery($accountQuery, $ownershipState);

        $accountIds = $accountQuery
            ->pluck('_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($accountIds === []) {
            $profileQuery->whereRaw(['_id' => ['$exists' => false]]);

            return;
        }

        $profileQuery->whereIn('account_id', $accountIds);
    }

    private function extractOwnershipState(array $queryParams): ?string
    {
        $topLevel = $queryParams['ownership_state'] ?? null;
        if (is_string($topLevel) && trim($topLevel) !== '') {
            return trim($topLevel);
        }

        $filter = $queryParams['filter'] ?? null;
        if (! is_array($filter)) {
            return null;
        }

        $filterValue = $filter['ownership_state'] ?? null;
        if (is_string($filterValue) && trim($filterValue) !== '') {
            return trim($filterValue);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutOwnershipState(array $queryParams): array
    {
        unset($queryParams['ownership_state']);

        if (isset($queryParams['filter']) && is_array($queryParams['filter'])) {
            unset($queryParams['filter']['ownership_state']);
        }

        return $queryParams;
    }

    private function applyPublicSearchFilter(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->where('display_name', 'like', $pattern)
                ->orWhere('slug', 'like', $pattern)
                ->orWhere('taxonomy_terms.value', 'like', $pattern);
            });
    }

    /**
     * @param  array<string, array<int, string>>  $taxonomyFilters
     */
    private function applyPublicTaxonomyFilter(Builder $query, array $taxonomyFilters): void
    {
        $expression = $this->publicTaxonomyExpression($taxonomyFilters);
        if ($expression === []) {
            return;
        }

        $query->whereRaw($expression);
    }

    private function applyPublicVisibilityConstraint(Builder $query): void
    {
        $query->whereRaw($this->publicVisibilityConstraintExpression());
    }

    /**
     * @return array<string, mixed>
     */
    private function publicVisibilityConstraintExpression(): array
    {
        return ['visibility' => 'public'];
    }

    /**
     * @return array<int, string>
     */
    private function favoritableProfileTypes(): array
    {
        return TenantProfileType::query()
            ->where('capabilities.is_favoritable', true)
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function nearEligibleProfileTypes(): array
    {
        return TenantProfileType::query()
            ->where('capabilities.is_favoritable', true)
            ->where('capabilities.is_poi_enabled', true)
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $allowedTypes
     * @return array<int, string>
     */
    private function resolveEffectivePublicProfileTypes(array $queryParams, array $allowedTypes): array
    {
        if ($allowedTypes === []) {
            return [];
        }

        $topLevelRequested = $this->normalizeProfileTypeList($queryParams['profile_type'] ?? null);
        $filterPayload = $queryParams['filter'] ?? null;
        $filterRequested = is_array($filterPayload)
            ? $this->normalizeProfileTypeList($filterPayload['profile_type'] ?? null)
            : [];

        if ($topLevelRequested !== [] && $filterRequested !== []) {
            $requested = array_values(array_intersect($topLevelRequested, $filterRequested));
        } elseif ($topLevelRequested !== []) {
            $requested = $topLevelRequested;
        } else {
            $requested = $filterRequested;
        }

        if ($requested === []) {
            return $allowedTypes;
        }

        return array_values(array_intersect($allowedTypes, $requested));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function resolvePublicTaxonomyFilters(array $queryParams): array
    {
        $topLevel = $this->normalizeTaxonomyFilterList($queryParams['taxonomy'] ?? null);
        $filterPayload = $queryParams['filter'] ?? null;
        $nested = is_array($filterPayload)
            ? $this->normalizeTaxonomyFilterList($filterPayload['taxonomy'] ?? null)
            : [];

        if ($topLevel === []) {
            return $nested;
        }

        if ($nested === []) {
            return $topLevel;
        }

        foreach ($nested as $taxonomyType => $values) {
            $topLevel[$taxonomyType] = array_values(array_unique([
                ...($topLevel[$taxonomyType] ?? []),
                ...$values,
            ]));
        }

        return $topLevel;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function normalizeTaxonomyFilterList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = trim((string) ($entry['type'] ?? ''));
            $termValue = trim((string) ($entry['value'] ?? ''));
            if ($type === '' || $termValue === '') {
                continue;
            }

            $normalized[$type] ??= [];
            $normalized[$type][] = $termValue;
        }

        foreach ($normalized as $type => $values) {
            $normalized[$type] = array_values(array_unique($values));
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<string, mixed>
     */
    private function publicTaxonomyExpression(array $taxonomyFilters): array
    {
        if ($taxonomyFilters === []) {
            return [];
        }

        $and = [];
        foreach ($taxonomyFilters as $type => $values) {
            $or = [];
            foreach ($values as $value) {
                $or[] = [
                    'taxonomy_terms' => [
                        '$elemMatch' => [
                            'type' => $type,
                            'value' => $value,
                        ],
                    ],
                ];
            }

            if ($or === []) {
                continue;
            }

            $and[] = count($or) === 1 ? $or[0] : ['$or' => $or];
        }

        if ($and === []) {
            return [];
        }

        return count($and) === 1 ? $and[0] : ['$and' => $and];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeProfileTypeList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($items as $item) {
            $type = trim((string) $item);
            if ($type === '') {
                continue;
            }
            $normalized[] = $type;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutPublicProfileTypeFilters(array $queryParams): array
    {
        unset($queryParams['profile_type']);

        if (isset($queryParams['filter']) && is_array($queryParams['filter'])) {
            unset($queryParams['filter']['profile_type']);
            if ($queryParams['filter'] === []) {
                unset($queryParams['filter']);
            }
        }

        return $queryParams;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutPublicTaxonomyFilters(array $queryParams): array
    {
        unset($queryParams['taxonomy']);

        if (isset($queryParams['filter']) && is_array($queryParams['filter'])) {
            unset($queryParams['filter']['taxonomy']);
            if ($queryParams['filter'] === []) {
                unset($queryParams['filter']);
            }
        }

        return $queryParams;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicSearchExpression(string $search): array
    {
        $query = trim($search);
        if ($query === '') {
            return [];
        }

        $regex = new Regex(preg_quote($query, '/'), 'i');

        return [
            '$or' => [
                ['display_name' => $regex],
                ['slug' => $regex],
                ['taxonomy_terms.value' => $regex],
            ],
        ];
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveAggregateRowId(array $payload): ?string
    {
        return $this->toObjectIdString($payload['_id'] ?? $payload['id'] ?? null);
    }

    private function toObjectIdString(mixed $value): ?string
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        if (is_array($value) && isset($value['$oid']) && is_string($value['$oid']) && trim($value['$oid']) !== '') {
            return trim($value['$oid']);
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
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

        if (is_object($value)) {
            if (method_exists($value, 'getArrayCopy')) {
                $copy = $value->getArrayCopy();
                if (is_array($copy)) {
                    return $copy;
                }
            }

            return get_object_vars($value);
        }

        return [];
    }

    private function hydrateOwnershipState(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        /** @var Collection<int, AccountProfile> $profiles */
        $profiles = $paginator->getCollection()
            ->filter(static fn ($item): bool => $item instanceof AccountProfile)
            ->values();
        $accountsById = $this->loadAccountsById($profiles);
        $userOperatedLookup = $this->ownershipStateService->userOperatedAccountIdLookup(
            array_keys($accountsById)
        );

        $paginator->setCollection(
            $profiles
                ->map(
                    fn (AccountProfile $profile): array => $this->format(
                        $profile,
                        $accountsById[(string) $profile->account_id] ?? null,
                        $userOperatedLookup
                    )
                )
                ->values()
        );

        return $paginator;
    }

    protected function baseSearchableFields(): array
    {
        return (new AccountProfile)->getFillable();
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

    protected function extraSearchableFields(): array
    {
        return ['account_id'];
    }
}
