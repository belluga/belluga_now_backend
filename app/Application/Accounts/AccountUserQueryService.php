<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use MongoDB\BSON\ObjectId;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AccountUserQueryService extends AbstractQueryService
{
    public function paginate(
        Account $account,
        array $queryParams,
        bool $includeArchived,
        int $perPage = 15
    ): LengthAwarePaginator {
        return $this->buildPaginator(
            AccountUser::query()->where('account_roles.account_id', $account->id),
            $queryParams,
            $includeArchived,
            $perPage
        );
    }

    public function findByIdForAccountOrFail(Account $account, string $userId): AccountUser
    {
        $user = AccountUser::where('_id', new ObjectId($userId))
            ->where('account_roles.account_id', $account->id)
            ->first();

        if (! $user) {
            throw (new ModelNotFoundException())->setModel(AccountUser::class, [$userId]);
        }

        return $user;
    }

    public function findByIdOrFail(string $userId): AccountUser
    {
        $user = AccountUser::query()
            ->where('_id', new ObjectId($userId))
            ->first();

        if (! $user) {
            throw (new ModelNotFoundException())->setModel(AccountUser::class, [$userId]);
        }

        return $user;
    }

    public function findByEmail(string $email): ?AccountUser
    {
        return AccountUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->first();
    }

    protected function baseSearchableFields(): array
    {
        return array_diff(
            (new AccountUser())->getFillable(),
            [
                'password',
                'credentials',
                'consents',
                'promotion_audit',
                'merged_source_ids',
                'fingerprints',
            ]
        );
    }

    protected function stringFields(): array
    {
        return ['name', 'identity_state'];
    }

    protected function arrayFields(): array
    {
        return ['emails', 'phones'];
    }

    protected function dateFields(): array
    {
        return ['first_seen_at', 'registered_at', 'created_at', 'updated_at', 'deleted_at'];
    }
}
