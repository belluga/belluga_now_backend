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

        return $this->buildPaginator($query, $queryParams, $includeArchived, $perPage)
            ->through(static function (Account $account): array {
                return [
                    'id' => (string) $account->_id,
                    'name' => $account->name,
                    'slug' => $account->slug,
                    'document' => $account->document,
                    'created_at' => $account->created_at?->toJSON(),
                    'updated_at' => $account->updated_at?->toJSON(),
                    'deleted_at' => $account->deleted_at?->toJSON(),
                ];
            });
    }

    protected function baseSearchableFields(): array
    {
        return array_diff((new Account())->getFillable(), ['document']);
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
}
