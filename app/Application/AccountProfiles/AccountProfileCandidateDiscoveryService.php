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
}
