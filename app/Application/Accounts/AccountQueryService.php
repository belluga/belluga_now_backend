<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Pagination\LengthAwarePaginator;
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

        return $this->buildPaginator(
            $query,
            $this->withoutOwnershipState($queryParams),
            $includeArchived,
            $perPage
        )->through(fn (Account $account): array => $this->format($account));
    }

    public function findBySlugOrFail(string $slug, bool $onlyTrashed = false): Account
    {
        $query = $onlyTrashed ? Account::onlyTrashed() : Account::query();

        return $query->where('slug', $slug)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Account $account): array
    {
        return [
            'id' => (string) $account->_id,
            'name' => $account->name,
            'slug' => $account->slug,
            'document' => $account->document,
            'organization_id' => $account->organization_id ?? null,
            'ownership_state' => $this->ownershipStateService->deriveOwnershipState($account),
            'created_at' => $account->created_at?->toJSON(),
            'updated_at' => $account->updated_at?->toJSON(),
            'deleted_at' => $account->deleted_at?->toJSON(),
        ];
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
}
