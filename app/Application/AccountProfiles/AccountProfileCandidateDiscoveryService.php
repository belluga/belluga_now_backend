<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use MongoDB\BSON\Regex;

final class AccountProfileCandidateDiscoveryService
{
    public const SCOPE_QUERYABLE = 'queryable';

    public const SCOPE_CONTACT_CAPABLE = 'contact_capable';

    private const MAX_PAGE = 50;

    private const MAX_BROWSE_ROWS = 2500;

    /**
     * @return array<int, string>
     */
    public static function scopes(): array
    {
        return [self::SCOPE_QUERYABLE, self::SCOPE_CONTACT_CAPABLE];
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
        $eligibleTypes = $this->eligibleTypes($scope);
        if ($eligibleTypes === []) {
            return $this->terminalEnvelope($page, $perPage);
        }

        $skip = ($page - 1) * $perPage;
        $query = AccountProfile::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('profile_type', $eligibleTypes)
            ->where('name_search_key', new Regex('^'.preg_quote($normalizedSearch, '/')));

        if ($scope === self::SCOPE_CONTACT_CAPABLE) {
            $query->where('contact_mode', AccountProfileContactChannelsService::CONTACT_MODE_OWN);
        }

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

        $eligibleTypes = $this->eligibleTypes($scope);
        if ($eligibleTypes === []) {
            return collect();
        }

        $query = AccountProfile::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('profile_type', $eligibleTypes)
            ->whereIn('_id', $profileIds);
        if ($scope === self::SCOPE_CONTACT_CAPABLE) {
            $query->where('contact_mode', AccountProfileContactChannelsService::CONTACT_MODE_OWN);
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

        $queryableTypes = array_flip($this->eligibleTypes(self::SCOPE_QUERYABLE));
        $contactCapableTypes = array_flip($this->eligibleTypes(self::SCOPE_CONTACT_CAPABLE));

        /** @var Collection<int, AccountProfile> $profiles */
        $profiles = AccountProfile::withTrashed()
            ->whereIn('_id', $profileIds)
            ->get(['_id', 'display_name', 'profile_type', 'is_active', 'contact_mode', 'deleted_at']);
        $profilesById = $profiles->keyBy(static fn (AccountProfile $profile): string => (string) $profile->getKey());

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
                'is_queryable_candidate' => $isActive && isset($queryableTypes[$profileType]),
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
