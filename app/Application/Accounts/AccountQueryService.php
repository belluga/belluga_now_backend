<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use MongoDB\BSON\ObjectId;

class AccountQueryService extends AbstractQueryService
{
    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService
    ) {}

    public function paginateForUser(
        AccountUser|LandlordUser $user,
        array $queryParams,
        bool $includeArchived,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = Account::query();

        if ($user instanceof AccountUser) {
            $accessIds = array_map(
                static fn ($id): ObjectId => new ObjectId((string) $id),
                $user->getAccessToIds()
            );

            $query->whereRaw(['_id' => ['$in' => $accessIds]]);
        }

        $ownershipState = $this->extractOwnershipState($queryParams);
        if ($ownershipState !== null) {
            $this->ownershipStateService->applyOwnershipFilterToAccountsQuery(
                $query,
                $ownershipState
            );
        }

        $searchQuery = $this->extractSearchQuery($queryParams);
        if ($searchQuery !== null) {
            $this->applySearchFilter($query, $searchQuery);
        }

        $paginator = $this->buildPaginator(
            $query,
            $this->withoutSearchAndOwnershipState($queryParams),
            $includeArchived,
            $perPage
        );

        $accounts = $paginator->getCollection()
            ->filter(static fn ($item): bool => $item instanceof Account)
            ->values();
        $avatarUrlsByAccountId = $this->loadAvatarUrlsByAccountId(
            $accounts
                ->map(static fn (Account $account): string => (string) $account->_id)
                ->all()
        );

        $paginator->setCollection(
            $accounts
                ->map(function (Account $account) use ($avatarUrlsByAccountId): array {
                    $accountId = (string) $account->_id;

                    return $this->format(
                        $account,
                        $avatarUrlsByAccountId[$accountId] ?? null,
                        true
                    );
                })
                ->values()
        );

        return $paginator;
    }

    public function findBySlugOrFail(string $slug, bool $onlyTrashed = false): Account
    {
        $query = $onlyTrashed ? Account::onlyTrashed() : Account::query();

        return $query->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(
        Account $account,
        ?string $avatarUrl = null,
        bool $avatarResolved = false
    ): array {
        return [
            'id' => (string) $account->_id,
            'name' => $account->name,
            'slug' => $account->slug,
            'document' => $account->document,
            'organization_id' => $account->organization_id ?? null,
            'ownership_state' => $this->ownershipStateService->deriveOwnershipState($account),
            'avatar_url' => $avatarResolved
                ? $avatarUrl
                : ($avatarUrl ?? $this->resolveAvatarUrlForAccount($account)),
            'created_at' => $account->created_at?->toJSON(),
            'updated_at' => $account->updated_at?->toJSON(),
            'deleted_at' => $account->deleted_at?->toJSON(),
        ];
    }

    /**
     * @param  array<int, string>  $accountIds
     * @return array<string, string|null>
     */
    private function loadAvatarUrlsByAccountId(array $accountIds): array
    {
        $normalizedIds = array_values(
            array_filter(
                array_map(static fn (string $id): string => trim($id), $accountIds),
                static fn (string $id): bool => $id !== ''
            )
        );
        if ($normalizedIds === []) {
            return [];
        }

        $avatarsByAccountId = array_fill_keys($normalizedIds, null);
        $profiles = AccountProfile::query()
            ->whereIn('account_id', $normalizedIds)
            ->orderByDesc('updated_at')
            ->get(['account_id', 'avatar_url']);

        foreach ($profiles as $profile) {
            $accountId = trim((string) $profile->account_id);
            if ($accountId === '' || ! array_key_exists($accountId, $avatarsByAccountId)) {
                continue;
            }
            if ($avatarsByAccountId[$accountId] !== null) {
                continue;
            }
            $avatarsByAccountId[$accountId] = $this->normalizeAvatarUrl($profile->avatar_url);
        }

        return $avatarsByAccountId;
    }

    private function resolveAvatarUrlForAccount(Account $account): ?string
    {
        $profile = AccountProfile::query()
            ->where('account_id', (string) $account->_id)
            ->orderByDesc('updated_at')
            ->first();
        if (! $profile) {
            return null;
        }

        return $this->normalizeAvatarUrl($profile->avatar_url);
    }

    private function normalizeAvatarUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    protected function baseSearchableFields(): array
    {
        return array_diff((new Account)->getFillable(), ['document']);
    }

    protected function stringFields(): array
    {
        return ['name', 'slug'];
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
        return ['organization_id'];
    }

    private function applySearchFilter(\Illuminate\Database\Eloquent\Builder $query, string $searchQuery): void
    {
        $regex = $this->buildContainsRegexPattern($searchQuery);

        $query->whereRaw([
            '$or' => [
                ['name' => ['$regex' => $regex, '$options' => 'i']],
                ['slug' => ['$regex' => $regex, '$options' => 'i']],
                ['document.number' => ['$regex' => $regex, '$options' => 'i']],
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
    private function withoutSearchAndOwnershipState(array $queryParams): array
    {
        unset($queryParams['ownership_state'], $queryParams['search'], $queryParams['q']);

        if (isset($queryParams['filter']) && is_array($queryParams['filter'])) {
            unset($queryParams['filter']['ownership_state']);
        }

        Arr::forget($queryParams, 'filter.search');
        Arr::forget($queryParams, 'filter.q');

        return $queryParams;
    }
}
