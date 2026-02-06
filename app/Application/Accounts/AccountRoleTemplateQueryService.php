<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use MongoDB\BSON\ObjectId;

class AccountRoleTemplateQueryService
{
    public function paginate(bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = AccountRoleTemplate::query();

        if ($includeArchived) {
            $query->onlyTrashed();
        }

        return $query->paginate($perPage);
    }

    public function findByIdForAccountOrFail(Account $account, string $roleId): AccountRoleTemplate
    {
        $role = $account->roleTemplates()
            ->where('_id', new ObjectId($roleId))
            ->first();

        if (! $role) {
            throw (new ModelNotFoundException())->setModel(AccountRoleTemplate::class, [$roleId]);
        }

        return $role;
    }
}
