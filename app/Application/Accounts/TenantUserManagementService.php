<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\AccountUser;
use Illuminate\Pagination\LengthAwarePaginator;
use MongoDB\BSON\ObjectId;

class TenantUserManagementService
{
    public function paginate(bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        return AccountUser::query()
            ->when(
                $includeArchived,
                static fn ($query) => $query->withTrashed()
            )
            ->paginate($perPage);
    }

    public function find(string $id): AccountUser
    {
        return AccountUser::query()
            ->where('_id', new ObjectId($id))
            ->firstOrFail();
    }

    public function delete(string $id): void
    {
        $this->find($id)->delete();
    }

    public function restore(string $id): AccountUser
    {
        $user = AccountUser::query()
            ->onlyTrashed()
            ->where('_id', new ObjectId($id))
            ->firstOrFail();

        $user->restore();

        return $user->fresh();
    }

    public function forceDelete(string $id): void
    {
        $user = AccountUser::query()
            ->onlyTrashed()
            ->where('_id', new ObjectId($id))
            ->firstOrFail();

        $user->forceDelete();
    }
}
