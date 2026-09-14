<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use MongoDB\Model\BSONDocument;

final class AccountProfileCandidateDiscoveryService
{
    public const SCOPE_QUERYABLE = 'queryable';

    public const SCOPE_CONTACT_CAPABLE = 'contact_capable';

    public const SCOPE_HOME_FAVORITES_PINNED_PROFILE = 'home_favorites_pinned_profile';

    private const MAX_PAGE = 50;

    private const MAX_BROWSE_ROWS = 2500;

    public function __construct(
        private readonly AccountProfilePublicCatalogSnapshotReader $publicCatalogSnapshotReader,
        private readonly HomeFavoritesPinnedProfileService $homeFavoritesPinnedProfileService,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return [
            self::SCOPE_QUERYABLE,
            self::SCOPE_CONTACT_CAPABLE,
            self::SCOPE_HOME_FAVORITES_PINNED_PROFILE,
        ];
    }

    /**
     * @return array{data: array<int, array{id: string, display_name: string}>, page: int, per_page: int, has_more: bool, browse_limit_reached: bool}
     */
    public function page(
        string $scope,
        string $normalizedSearch,
        int $page,
        int $perPage,
        ?string $excludedProfileId = null,
    ): array {
        if ($scope === self::SCOPE_HOME_FAVORITES_PINNED_PROFILE) {
            return $this->homeFavoritesPinnedProfilePage(
                $normalizedSearch,
                $page,
                $perPage,
                $excludedProfileId,
            );
        }

        $scopeExpression = $this->scopeExpression($scope);
        if ($scopeExpression === null) {
            return $this->terminalEnvelope($page, $perPage);
        }

        $query = AccountProfile::query()
            ->whereRaw(AccountProfileSearchV1::mongoScopedOrPredicate(
                $scopeExpression,
                'name_search_key',
                'search_terms',
                $normalizedSearch,
            ));

        $skip = ($page - 1) * $perPage;

        if ($excludedProfileId !== null) {
            $query->where('_id', '!=', $excludedProfileId);
        }

        /** @var Collection<int, AccountProfile> $rows */
        $rows = $query
            ->orderBy('name_search_key')
            ->orderBy('_id')
            ->skip($skip)
            ->take($perPage + 1)
            ->get(['_id', 'display_name']);

        $hasSentinel = $rows->count() > $perPage;
        $items = $rows
            ->take($perPage)
            ->map(static fn (AccountProfile $profile): array => [
                'id' => (string) $profile->getKey(),
                'display_name' => (string) $profile->display_name,
            ])
            ->values()
            ->all();

        $nextSameSizePageIsAdmissible = $page < self::MAX_PAGE
            && ($skip + (2 * $perPage)) <= self::MAX_BROWSE_ROWS;

        return [
            'data' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasSentinel && $nextSameSizePageIsAdmissible,
            'browse_limit_reached' => $hasSentinel && ! $nextSameSizePageIsAdmissible,
        ];
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return Collection<int, AccountProfile>
     */
    public function eligibleProfilesByIds(
        string $scope,
        array $profileIds,
        ?string $excludedProfileId = null,
    ): Collection {
        $profileIds = collect($profileIds)
            ->map(static fn (mixed $profileId): string => trim((string) $profileId))
            ->filter(static fn (string $profileId): bool => $profileId !== '')
            ->unique()
            ->values()
            ->all();
        if ($profileIds === []) {
            return collect();
        }

        if ($scope === self::SCOPE_HOME_FAVORITES_PINNED_PROFILE) {
            return collect($profileIds)
                ->reject(static fn (string $profileId): bool => $profileId === $excludedProfileId)
                ->map(fn (string $profileId): ?AccountProfile =>
                    $this->homeFavoritesPinnedProfileService->findEligibleProfile($profileId))
                ->filter(static fn (?AccountProfile $profile): bool => $profile instanceof AccountProfile)
                ->values();
        }

        $query = AccountProfile::query()
            ->whereIn('_id', $profileIds);
        if (! $this->applyScopeConstraint($query, $scope)) {
            return collect();
        }
        if ($excludedProfileId !== null) {
            $query->where('_id', '!=', $excludedProfileId);
        }

        /** @var Collection<int, AccountProfile> $profiles */
        $profiles = $query->get();

        return $profiles;
    }

    /**
     * Returns only summaries for already-persisted links. This deliberately does
     * not reuse a candidate page: a readback must expose stale and soft-deleted
     * selections so the next write can require an explicit repair.
     *
     * @param  array<int, string>  $profileIds
     * @return array<string, array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}>
     */
    public function selectedSummariesByIds(array $profileIds): array
    {
        $profileIds = collect($profileIds)
            ->map(static fn (mixed $profileId): string => trim((string) $profileId))
            ->filter(static fn (string $profileId): bool => $profileId !== '')
            ->unique()
            ->values()
            ->all();
        if ($profileIds === []) {
            return [];
        }

        /** @var Collection<int, AccountProfile> $profiles */
        $profiles = AccountProfile::withTrashed()
            ->whereIn('_id', $profileIds)
            ->get(['_id', 'display_name', 'profile_type', 'is_active', 'visibility', 'contact_mode', 'deleted_at']);
        $profilesById = $profiles->keyBy(static fn (AccountProfile $profile): string => (string) $profile->getKey());

        return $this->selectedSummariesFromProfiles($profileIds, $profilesById);
    }

    /**
     * Formats the bounded Profile documents already hydrated by a relationship
     * aggregation. Missing Profiles deliberately remain visible as unavailable
     * selections so an administrator can remove a stale relationship.
     *
     * @param  array<int, string>  $profileIds
     * @param  array<string, array<string, mixed>>  $documentsById
     * @return array<string, array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}>
     */
    public function selectedSummariesFromDocuments(array $profileIds, array $documentsById): array
    {
        $profilesById = collect($documentsById)->mapWithKeys(static function (array $document, string $profileId): array {
            $profile = (new AccountProfile)->newFromBuilder($document);

            return [$profileId => $profile];
        });

        return $this->selectedSummariesFromProfiles($profileIds, $profilesById);
    }

    /**
     * @param  array<int, string>  $profileIds
     * @param  Collection<string, AccountProfile>  $profilesById
     * @return array<string, array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}>
     */
    private function selectedSummariesFromProfiles(array $profileIds, Collection $profilesById): array
    {
        $queryablePolicy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
        $contactCapableTypes = array_flip($this->eligibleTypes(self::SCOPE_CONTACT_CAPABLE));

        $summaries = [];
        foreach ($profileIds as $profileId) {
            /** @var AccountProfile|null $profile */
            $profile = $profilesById->get($profileId);
            if (! $profile instanceof AccountProfile) {
                $summaries[$profileId] = $this->unavailableSelectedSummary($profileId);

                continue;
            }

            $isActive = (bool) $profile->is_active && $profile->deleted_at === null;
            $profileType = trim((string) $profile->profile_type);
            $displayName = trim((string) $profile->display_name);
            $summaries[$profileId] = [
                'id' => $profileId,
                'display_name' => $displayName === '' ? null : $displayName,
                'is_queryable_candidate' => $queryablePolicy->isPubliclyExposed($profile),
                'is_contact_capable_candidate' => $isActive
                    && isset($contactCapableTypes[$profileType])
                    && trim((string) $profile->contact_mode) === AccountProfileContactChannelsService::CONTACT_MODE_OWN,
            ];
        }

        return $summaries;
    }

    /**
     * @return array<int, string>
     */
    private function eligibleTypes(string $scope): array
    {
        $query = TenantProfileType::query();
        match ($scope) {
            self::SCOPE_QUERYABLE => $query->queryable(),
            self::SCOPE_CONTACT_CAPABLE => $query->contactChannelsEnabled(),
            default => throw new InvalidArgumentException("Unsupported account profile candidate scope [{$scope}]."),
        };

        return $query
            ->pluck('type')
            ->map(static fn (mixed $type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AccountProfile>|\Illuminate\Database\Eloquent\Builder<AccountProfile>  $query
     */
    private function applyScopeConstraint($query, string $scope): bool
    {
        if ($scope === self::SCOPE_QUERYABLE) {
            $policy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
            if ($policy->catalogTypeKeys() === []) {
                return false;
            }

            $policy->applyCatalogConstraint($query);

            return true;
        }

        $eligibleTypes = $this->eligibleTypes($scope);
        if ($eligibleTypes === []) {
            return false;
        }

        $query
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('profile_type', $eligibleTypes);

        if ($scope === self::SCOPE_CONTACT_CAPABLE) {
            $query->where('contact_mode', AccountProfileContactChannelsService::CONTACT_MODE_OWN);
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function scopeExpression(string $scope): ?array
    {
        if ($scope === self::SCOPE_QUERYABLE) {
            $policy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();

            return $policy->catalogTypeKeys() === []
                ? null
                : $policy->catalogMatchExpression();
        }

        $eligibleTypes = $this->eligibleTypes($scope);
        if ($eligibleTypes === []) {
            return null;
        }

        $clauses = [
            ['is_active' => true],
            ['deleted_at' => null],
            ['profile_type' => ['$in' => $eligibleTypes]],
        ];
        if ($scope === self::SCOPE_CONTACT_CAPABLE) {
            $clauses[] = ['contact_mode' => AccountProfileContactChannelsService::CONTACT_MODE_OWN];
        }

        return ['$and' => $clauses];
    }

    /**
     * @return array{data: array<int, array{id: string, display_name: string}>, page: int, per_page: int, has_more: bool, browse_limit_reached: bool}
     */
    private function homeFavoritesPinnedProfilePage(
        string $normalizedSearch,
        int $page,
        int $perPage,
        ?string $excludedProfileId,
    ): array {
        $skip = ($page - 1) * $perPage;
        $match = $this->homeFavoritesPinnedProfileService
            ->candidateProfileMatchExpression($normalizedSearch);
        if ($excludedProfileId !== null) {
            $match = [
                '$and' => [
                    $match,
                    ['$expr' => ['$ne' => [['$toString' => '$_id'], $excludedProfileId]]],
                ],
            ];
        }

        $pipeline = [
            ['$match' => $match],
            ...$this->homeFavoritesPinnedProfileService->candidateAccountGateStages(),
            ['$sort' => ['name_search_key' => 1, '_id' => 1]],
            ['$skip' => $skip],
            ['$limit' => $perPage + 1],
            ['$project' => ['_id' => 1, 'display_name' => 1]],
        ];
        $rows = AccountProfile::raw(fn ($collection) => $collection->aggregate($pipeline));
        $documents = collect($rows)->map(static function (mixed $row): array {
            if ($row instanceof AccountProfile) {
                return [
                    '_id' => (string) $row->getKey(),
                    'display_name' => (string) $row->display_name,
                ];
            }
            if ($row instanceof BSONDocument) {
                return $row->getArrayCopy();
            }
            if ($row instanceof Arrayable) {
                return $row->toArray();
            }

            return (array) $row;
        });
        $hasSentinel = $documents->count() > $perPage;
        $nextSameSizePageIsAdmissible = $page < self::MAX_PAGE
            && ($skip + (2 * $perPage)) <= self::MAX_BROWSE_ROWS;

        return [
            'data' => $documents
                ->take($perPage)
                ->map(static fn (array $profile): array => [
                    'id' => (string) ($profile['_id'] ?? ''),
                    'display_name' => (string) ($profile['display_name'] ?? ''),
                ])
                ->values()
                ->all(),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasSentinel && $nextSameSizePageIsAdmissible,
            'browse_limit_reached' => $hasSentinel && ! $nextSameSizePageIsAdmissible,
        ];
    }

    /**
     * @return array{data: array<int, array{id: string, display_name: string}>, page: int, per_page: int, has_more: false, browse_limit_reached: false}
     */
    private function terminalEnvelope(int $page, int $perPage): array
    {
        return [
            'data' => [],
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => false,
            'browse_limit_reached' => false,
        ];
    }

    /**
     * @return array{id: string, display_name: null, is_queryable_candidate: false, is_contact_capable_candidate: false}
     */
    private function unavailableSelectedSummary(string $profileId): array
    {
        return [
            'id' => $profileId,
            'display_name' => null,
            'is_queryable_candidate' => false,
            'is_contact_capable_candidate' => false,
        ];
    }
}
