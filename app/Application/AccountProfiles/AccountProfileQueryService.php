<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use MongoDB\BSON\ObjectId;

class AccountProfileQueryService extends AbstractQueryService
{
    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService
    ) {
    }

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

    public function publicPaginate(array $queryParams, int $perPage = 15): LengthAwarePaginator
    {
        $allowedTypes = TenantProfileType::query()->pluck('type')->all();
        $query = $queryParams;
        if (! empty($allowedTypes)) {
            $existingFilters = (array) ($query['filter'] ?? []);
            $requested = $existingFilters['profile_type'] ?? null;
            if ($requested !== null) {
                $requestedList = is_array($requested) ? $requested : [$requested];
                $effectiveTypes = array_values(array_intersect($allowedTypes, $requestedList));
            } else {
                $effectiveTypes = $allowedTypes;
            }

            $query['filter'] = array_merge(
                $existingFilters,
                ['profile_type' => $effectiveTypes]
            );
        }

        return $this->paginate($query, false, $perPage);
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
            throw (new ModelNotFoundException())->setModel(AccountProfile::class, [$profileId]);
        }

        return $profile;
    }

    /**
     * @param array<string, bool> $userOperatedLookup
     * @return array<string, mixed>
     */
    private function format(
        AccountProfile $profile,
        ?Account $account = null,
        array $userOperatedLookup = []
    ): array
    {
        $resolvedAccount = $account
            ?? Account::query()->where('_id', $profile->account_id)->first();

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'avatar_url' => $profile->avatar_url,
            'cover_url' => $profile->cover_url,
            'bio' => $profile->bio,
            'content' => $profile->content,
            'taxonomy_terms' => $profile->taxonomy_terms ?? [],
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
     * @param Collection<int, AccountProfile> $profiles
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
     * @param mixed $location
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

    protected function baseSearchableFields(): array
    {
        return (new AccountProfile())->getFillable();
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
