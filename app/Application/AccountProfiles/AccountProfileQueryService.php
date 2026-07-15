<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Shared\Query\AbstractQueryService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;

class AccountProfileQueryService extends AbstractQueryService
{
    private const PUBLIC_PAGE_SIZE_DEFAULT = 15;

    private const PUBLIC_NEAR_PAGE_SIZE_DEFAULT = 10;

    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = AccountProfile::query();
        $queryParams = $this->applyAdminCandidateFilters($query, $queryParams);

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

    public function paginateContactSourceCandidates(
        ?string $excludedProfileId,
        int $perPage = InputConstraints::PUBLIC_PAGE_SIZE_MAX,
    ): LengthAwarePaginator {
        $eligibleTypes = $this->typeSetProvider->contactChannelsEnabledTypes();
        if ($eligibleTypes === []) {
            return new LengthAwarePaginator(
                collect(),
                0,
                $perPage,
                max(1, (int) request()->query('page', 1)),
            );
        }

        $query = AccountProfile::query()
            ->where('contact_mode', 'own')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('profile_type', $eligibleTypes);

        $normalizedExcludedProfileId = trim((string) $excludedProfileId);
        if ($normalizedExcludedProfileId !== '') {
            $query->where('_id', '!=', $normalizedExcludedProfileId);
        }

        $paginator = $query
            ->orderBy('display_name')
            ->orderBy('_id')
            ->paginate($perPage);

        return $this->hydrateOwnershipState($paginator);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function applyAdminCandidateFilters(Builder $query, array $queryParams): array
    {
        $queryableOnly = filter_var(
            $queryParams['queryable_only'] ?? false,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE
        ) ?? false;
        if ($queryableOnly) {
            $queryableTypes = $this->queryableProfileTypes();
            if ($queryableTypes === []) {
                $query->whereRaw(['_id' => ['$exists' => false]]);
            } else {
                $query->whereIn('profile_type', $queryableTypes)
                    ->where('is_active', true);
            }
        }

        $excludedProfileId = trim((string) ($queryParams['exclude_account_profile_id'] ?? ''));
        if ($excludedProfileId !== '') {
            $query->where('_id', '!=', $excludedProfileId);
        }

        unset($queryParams['queryable_only'], $queryParams['exclude_account_profile_id']);

        return $queryParams;
    }

    public function publicPaginate(array $queryParams, int $perPage = 15): LengthAwarePaginator
    {
        $perPage = $this->normalizePublicPageSize($perPage);
        $page = $this->normalizePublicPage($queryParams['page'] ?? 1);
        $allowedTypes = $this->publiclyDiscoverableProfileTypes();
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
            $perPage,
            $page
        );

        return $this->hydrateOwnershipState($paginator);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    public function publicPageEnvelope(array $queryParams, int $perPage = 15): array
    {
        $perPage = $this->normalizePublicPageSize($perPage);
        $page = $this->normalizePublicPage($queryParams['page'] ?? 1);
        $allowedTypes = $this->publiclyDiscoverableProfileTypes();
        $selectedTypes = $this->resolveEffectivePublicProfileTypes($queryParams, $allowedTypes);
        $hasExplicitTypeFilter = $this->hasExplicitPublicTypeFilter($queryParams);
        $selectedTypesForItems = $hasExplicitTypeFilter && $selectedTypes === []
            ? ['__no_public_profile_match__']
            : $selectedTypes;
        $taxonomyFilters = $this->resolvePublicTaxonomyFilters($queryParams);
        $search = trim((string) ($queryParams['search'] ?? ''));

        if ($allowedTypes === []) {
            return [
                'data' => [],
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => 1,
                'total' => 0,
                'has_more' => false,
                'discovery_filter_facets' => $this->emptyPublicDiscoveryFilterFacetsPayload(),
            ];
        }

        $aggregate = $this->runPublicDiscoveryAggregate(
            allowedTypes: $allowedTypes,
            selectedTypes: $selectedTypesForItems,
            taxonomyFilters: $taxonomyFilters,
            search: $search,
            page: $page,
            perPage: $perPage,
        );

        $orderedIds = [];
        foreach ($aggregate['page_rows'] as $row) {
            $payload = $this->normalizeDocument($row);
            $id = $this->resolveAggregateRowId($payload);
            if ($id === null) {
                continue;
            }
            $orderedIds[] = $id;
        }

        $profilesById = [];
        if ($orderedIds !== []) {
            $profiles = AccountProfile::query()
                ->whereIn('_id', $orderedIds)
                ->get();
            foreach ($profiles as $profile) {
                $profilesById[(string) $profile->getKey()] = $profile;
            }
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
            ->map(fn (AccountProfile $profile): array => $this->format(
                $profile,
                $accountsById[(string) $profile->account_id] ?? null,
                $userOperatedLookup
            ))
            ->values()
            ->all();

        $total = $aggregate['total'];
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

        return [
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'total' => $total,
            'has_more' => $page < $lastPage,
            'discovery_filter_facets' => $aggregate['discovery_filter_facets'],
        ];
    }

    private function normalizePublicPageSize(int $perPage, int $default = self::PUBLIC_PAGE_SIZE_DEFAULT): int
    {
        if ($perPage <= 0) {
            return $default;
        }

        return min($perPage, InputConstraints::PUBLIC_PAGE_SIZE_MAX);
    }

    private function normalizePublicPage(mixed $value): int
    {
        $page = max(1, (int) $value);

        return min($page, InputConstraints::PUBLIC_PAGE_MAX);
    }

    /**
     * @return array<int, string>
     */
    private function queryableProfileTypes(): array
    {
        return $this->typeSetProvider->queryableTypes();
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
        $page = $this->normalizePublicPage($queryParams['page'] ?? 1);
        $pageSize = $this->normalizePublicPageSize(
            (int) ($queryParams['page_size'] ?? self::PUBLIC_NEAR_PAGE_SIZE_DEFAULT),
            self::PUBLIC_NEAR_PAGE_SIZE_DEFAULT
        );

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
            $geoNear['maxDistance'] = min(
                max(0.0, $maxDistance),
                (float) InputConstraints::PUBLIC_GEO_DISTANCE_MAX_METERS
            );
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

    /**
     * @param  array<int, string>  $allowedTypes
     * @param  array<int, string>  $selectedTypes
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array{page_rows: array<int, mixed>, total: int, discovery_filter_facets: array<string, mixed>}
     */
    private function runPublicDiscoveryAggregate(
        array $allowedTypes,
        array $selectedTypes,
        array $taxonomyFilters,
        string $search,
        int $page,
        int $perPage,
    ): array {
        $skip = ($page - 1) * $perPage;
        $limit = $perPage;

        $pipeline = $this->buildPublicDiscoveryAggregatePipeline(
            allowedTypes: $allowedTypes,
            selectedTypes: $selectedTypes,
            taxonomyFilters: $taxonomyFilters,
            search: $search,
            skip: $skip,
            limit: $limit,
        );
        EventBus::dispatch('account_profiles.public_discovery_aggregate', [
            'purpose' => 'public_discovery_page_with_runtime_facets',
            'pipeline' => $pipeline,
        ]);

        $rows = AccountProfile::raw(fn ($collection) => $collection->aggregate($pipeline));
        $payload = $this->normalizeDocument($rows->first());

        return [
            'page_rows' => $this->normalizeList($payload['page_rows'] ?? []),
            'total' => $this->extractAggregateTotal($payload['page_total'] ?? []),
            'discovery_filter_facets' => $this->formatPublicDiscoveryFilterFacets(
                $payload,
                $taxonomyFilters
            ),
        ];
    }

    /**
     * @param  array<int, string>  $allowedTypes
     * @param  array<int, string>  $selectedTypes
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<int, array<string, mixed>>
     */
    private function buildPublicDiscoveryAggregatePipeline(
        array $allowedTypes,
        array $selectedTypes,
        array $taxonomyFilters,
        string $search,
        int $skip,
        int $limit,
    ): array {
        $baseMatch = [
            '$and' => [
                ['is_active' => true],
                ['deleted_at' => null],
                ['profile_type' => ['$in' => $allowedTypes]],
                $this->publicVisibilityConstraintExpression(),
            ],
        ];

        if ($search !== '') {
            $baseMatch['$and'][] = $this->publicSearchExpression($search);
        }

        $facet = [
            'page_rows' => $this->buildPublicDiscoveryPageRowsBranch(
                $selectedTypes,
                $taxonomyFilters,
                $skip,
                $limit
            ),
            'page_total' => $this->buildPublicDiscoveryTotalBranch(
                $selectedTypes,
                $taxonomyFilters
            ),
            'type_keys' => $this->buildPublicDiscoveryTypeKeysBranch($taxonomyFilters),
            'taxonomy_base' => $this->buildPublicDiscoveryTaxonomyBranch(
                $selectedTypes,
                $taxonomyFilters
            ),
        ];

        foreach (array_keys($taxonomyFilters) as $taxonomyType) {
            $facet[$this->taxonomyFacetBranchKey($taxonomyType)] = $this->buildPublicDiscoveryTaxonomyBranch(
                $selectedTypes,
                $this->excludePublicTaxonomySelectionsForType(
                    $taxonomyFilters,
                    $taxonomyType
                )
            );
        }

        return [
            ['$match' => $baseMatch],
            ['$facet' => $facet],
        ];
    }

    /**
     * @param  array<int, string>  $selectedTypes
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<int, array<string, mixed>>
     */
    private function buildPublicDiscoveryPageRowsBranch(
        array $selectedTypes,
        array $taxonomyFilters,
        int $skip,
        int $limit,
    ): array {
        $pipeline = [];
        $this->applySelectedPublicProfileTypesMatch($pipeline, $selectedTypes);
        $this->applyPublicTaxonomySelectionMatch($pipeline, $taxonomyFilters);
        $pipeline[] = ['$sort' => ['created_at' => -1, '_id' => -1]];
        $pipeline[] = ['$skip' => $skip];
        $pipeline[] = ['$limit' => $limit];
        $pipeline[] = ['$project' => ['_id' => 1]];

        return $pipeline;
    }

    /**
     * @param  array<int, string>  $selectedTypes
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<int, array<string, mixed>>
     */
    private function buildPublicDiscoveryTotalBranch(
        array $selectedTypes,
        array $taxonomyFilters,
    ): array {
        $pipeline = [];
        $this->applySelectedPublicProfileTypesMatch($pipeline, $selectedTypes);
        $this->applyPublicTaxonomySelectionMatch($pipeline, $taxonomyFilters);
        $pipeline[] = ['$count' => 'total'];

        return $pipeline;
    }

    /**
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<int, array<string, mixed>>
     */
    private function buildPublicDiscoveryTypeKeysBranch(array $taxonomyFilters): array
    {
        $pipeline = [];
        $this->applyPublicTaxonomySelectionMatch($pipeline, $taxonomyFilters);
        $pipeline[] = ['$group' => ['_id' => '$profile_type']];
        $pipeline[] = [
            '$project' => [
                '_id' => 0,
                'filter_key' => '$_id',
            ],
        ];
        $pipeline[] = ['$sort' => ['filter_key' => 1]];

        return $pipeline;
    }

    /**
     * @param  array<int, string>  $selectedTypes
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<int, array<string, mixed>>
     */
    private function buildPublicDiscoveryTaxonomyBranch(
        array $selectedTypes,
        array $taxonomyFilters,
    ): array {
        $pipeline = [];
        $this->applySelectedPublicProfileTypesMatch($pipeline, $selectedTypes);
        $this->applyPublicTaxonomySelectionMatch($pipeline, $taxonomyFilters);
        $pipeline[] = ['$unwind' => '$taxonomy_terms'];
        $pipeline[] = [
            '$group' => [
                '_id' => [
                    'type' => '$taxonomy_terms.type',
                    'value' => '$taxonomy_terms.value',
                ],
                'label' => [
                    '$first' => [
                        '$ifNull' => [
                            '$taxonomy_terms.label',
                            '$taxonomy_terms.name',
                        ],
                    ],
                ],
                'group_label' => [
                    '$first' => [
                        '$ifNull' => [
                            '$taxonomy_terms.taxonomy_name',
                            '$taxonomy_terms.type',
                        ],
                    ],
                ],
            ],
        ];
        $pipeline[] = [
            '$project' => [
                '_id' => 0,
                'type' => '$_id.type',
                'value' => '$_id.value',
                'label' => '$label',
                'group_label' => '$group_label',
            ],
        ];
        $pipeline[] = ['$sort' => ['type' => 1, 'label' => 1, 'value' => 1]];

        return $pipeline;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<int, string>  $selectedTypes
     */
    private function applySelectedPublicProfileTypesMatch(array &$pipeline, array $selectedTypes): void
    {
        if ($selectedTypes === []) {
            return;
        }

        $pipeline[] = ['$match' => ['profile_type' => ['$in' => $selectedTypes]]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<string, array<int, string>>  $taxonomyFilters
     */
    private function applyPublicTaxonomySelectionMatch(array &$pipeline, array $taxonomyFilters): void
    {
        $expression = $this->publicTaxonomyExpression($taxonomyFilters);
        if ($expression === []) {
            return;
        }

        $pipeline[] = ['$match' => $expression];
    }

    /**
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<string, array<int, string>>
     */
    private function excludePublicTaxonomySelectionsForType(array $taxonomyFilters, string $excludedType): array
    {
        return array_filter(
            $taxonomyFilters,
            static fn (string $type): bool => $type !== $excludedType,
            ARRAY_FILTER_USE_KEY
        );
    }

    private function taxonomyFacetBranchKey(string $taxonomyType): string
    {
        return 'taxonomy_scope_'.trim($taxonomyType);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function extractAggregateTotal(array $rows): int
    {
        $first = $this->normalizeDocument($rows[0] ?? []);
        $total = $first['total'] ?? 0;

        return is_numeric($total) ? max(0, (int) $total) : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<int, string>>  $taxonomyFilters
     * @return array<string, mixed>
     */
    private function formatPublicDiscoveryFilterFacets(array $payload, array $taxonomyFilters): array
    {
        $filterKeys = [];
        foreach ($this->normalizeList($payload['type_keys'] ?? []) as $row) {
            $normalized = $this->normalizeDocument($row);
            $value = strtolower(trim((string) (
                $normalized['filter_key']
                ?? $normalized['_id']
                ?? $normalized['id']
                ?? ''
            )));
            if ($value === '') {
                continue;
            }
            $filterKeys[$value] = $value;
        }

        $taxonomyOptions = $this->formatMergedPublicTaxonomyFacetRows(
            $this->normalizeList($payload['taxonomy_base'] ?? []),
            $payload,
            array_keys($taxonomyFilters)
        );

        return [
            'surface' => 'discovery.account_profiles',
            'filter_keys' => array_values($filterKeys),
            'taxonomy_options' => $taxonomyOptions,
        ];
    }

    /**
     * @param  array<int, mixed>  $baseRows
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $selectedTaxonomyTypes
     * @return array<string, array<string, mixed>>
     */
    private function formatMergedPublicTaxonomyFacetRows(
        array $baseRows,
        array $payload,
        array $selectedTaxonomyTypes,
    ): array {
        $rowsByType = [];
        foreach ($baseRows as $row) {
            $normalized = $this->normalizeDocument($row);
            $type = strtolower(trim((string) ($normalized['type'] ?? '')));
            if ($type === '') {
                continue;
            }
            $rowsByType[$type][] = $normalized;
        }

        foreach ($selectedTaxonomyTypes as $type) {
            $rowsByType[$type] = array_map(
                fn (mixed $row): array => $this->normalizeDocument($row),
                $this->normalizeList($payload[$this->taxonomyFacetBranchKey($type)] ?? [])
            );
        }

        $taxonomyOptions = [];
        foreach ($rowsByType as $type => $rows) {
            $terms = [];
            $groupLabel = $type;
            foreach ($rows as $row) {
                $value = strtolower(trim((string) ($row['value'] ?? '')));
                $label = trim((string) ($row['label'] ?? $value));
                if ($value === '' || $label === '') {
                    continue;
                }
                $groupLabel = trim((string) ($row['group_label'] ?? $groupLabel));
                $terms[$value] = [
                    'value' => $value,
                    'label' => $label,
                ];
            }

            if ($terms === []) {
                continue;
            }

            uasort($terms, static fn (array $left, array $right): int => [$left['label'], $left['value']] <=> [$right['label'], $right['value']]);
            $taxonomyOptions[$type] = [
                'key' => $type,
                'label' => $groupLabel === '' ? $type : $groupLabel,
                'terms' => array_values($terms),
                'terms_truncated' => false,
                'terms_limit' => count($terms),
            ];
        }

        ksort($taxonomyOptions);

        return $taxonomyOptions;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPublicDiscoveryFilterFacetsPayload(): array
    {
        return [
            'surface' => 'discovery.account_profiles',
            'filter_keys' => [],
            'taxonomy_options' => [],
        ];
    }

    public function publicFindBySlugOrFail(string $slug): AccountProfile
    {
        $normalizedSlug = trim($slug);
        $allowedTypes = $this->publiclyNavigableProfileTypes();

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

    public function isPubliclyExposed(AccountProfile $profile): bool
    {
        if ((bool) ($profile->is_active ?? false) === false) {
            return false;
        }

        if (trim((string) ($profile->visibility ?? '')) !== 'public') {
            return false;
        }

        return $this->typeSetProvider->isPubliclyNavigable(
            trim((string) ($profile->profile_type ?? ''))
        );
    }

    public function findOrFail(string $profileId, bool $onlyTrashed = false): AccountProfile
    {
        $query = $onlyTrashed ? AccountProfile::onlyTrashed() : AccountProfile::query();

        return $this->findFromQueryOrFail($query, $profileId);
    }

    public function findWithTrashedOrFail(string $profileId): AccountProfile
    {
        return $this->findFromQueryOrFail(AccountProfile::withTrashed(), $profileId);
    }

    private function findFromQueryOrFail(Builder $query, string $profileId): AccountProfile
    {
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
        $slug = trim((string) ($profile->slug ?? ''));
        $canOpenPublicDetail = $slug !== ''
            && $this->typeSetProvider->isPubliclyNavigable((string) $profile->profile_type);

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'can_open_public_detail' => $canOpenPublicDetail,
            'public_detail_path' => $canOpenPublicDetail ? '/parceiro/'.$slug : null,
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
    private function publiclyDiscoverableProfileTypes(): array
    {
        return $this->typeSetProvider->publicDiscoverySurfaceTypes();
    }

    /**
     * @return array<int, string>
     */
    private function publiclyNavigableProfileTypes(): array
    {
        return $this->typeSetProvider->publiclyNavigableTypes();
    }

    /**
     * @return array<int, string>
     */
    private function nearEligibleProfileTypes(): array
    {
        return $this->typeSetProvider->publicPoiCatalogTypes();
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

    private function hasExplicitPublicTypeFilter(array $queryParams): bool
    {
        $topLevelRequested = $this->normalizeProfileTypeList($queryParams['profile_type'] ?? null);
        if ($topLevelRequested !== []) {
            return true;
        }

        $filterPayload = $queryParams['filter'] ?? null;
        if (! is_array($filterPayload)) {
            return false;
        }

        return $this->normalizeProfileTypeList($filterPayload['profile_type'] ?? null) !== [];
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

        $this->assertPublicTaxonomyFilterBudget($topLevel);

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

        $this->assertPublicTaxonomyFilterBudget($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, array<int, string>>  $taxonomyFilters
     */
    private function assertPublicTaxonomyFilterBudget(array $taxonomyFilters): void
    {
        $total = 0;
        foreach ($taxonomyFilters as $values) {
            $total += count($values);
        }

        if ($total <= InputConstraints::DISCOVERY_FILTER_PUBLIC_TAXONOMY_FILTERS_MAX) {
            return;
        }

        throw ValidationException::withMessages([
            'taxonomy' => [sprintf(
                'The public taxonomy filter may not contain more than %d selected terms.',
                InputConstraints::DISCOVERY_FILTER_PUBLIC_TAXONOMY_FILTERS_MAX
            )],
        ]);
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
            $flatKeys = [];
            foreach ($values as $value) {
                $flatKeys[] = "{$type}:{$value}";
            }

            $flatKeys = array_values(array_unique($flatKeys));
            if ($flatKeys === []) {
                continue;
            }

            $and[] = [
                'taxonomy_terms_flat' => ['$in' => $flatKeys],
            ];
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

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value));
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
        return array_values(array_diff(
            (new AccountProfile)->getFillable(),
            ['nested_profile_groups', 'gallery_groups']
        ));
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
