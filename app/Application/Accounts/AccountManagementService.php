<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Laravel\Eloquent\Builder as MongoBuilder;

class AccountManagementService
{
    public function paginateForUser(AccountUser|LandlordUser $user, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        if ($user instanceof LandlordUser) {
            return $this->paginateAccounts(Account::query(), $includeArchived, $perPage);
        }

        $accessIds = array_map(
            static fn ($id): ObjectId => new ObjectId((string) $id),
            $user->getAccessToIds()
        );

        return $this->paginateAccounts(
            Account::query()->whereRaw(['_id' => ['$in' => $accessIds]]),
            $includeArchived,
            $perPage
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{account: Account, role: AccountRoleTemplate}
     */
    public function create(array $payload): array
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($payload): array {
                $account = Account::create($payload);

                $role = $account->roleTemplates()->create([
                    'name' => 'Admin',
                    'description' => 'Administrador',
                    'permissions' => ['*'],
                ]);

                return [
                    'account' => $account->fresh(),
                    'role' => $role->fresh(),
                ];
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'account' => ['Account already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account' => ['Something went wrong when trying to create the account.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Account $account, array $attributes): Account
    {
        $account->fill($attributes);
        $account->save();

        return $account->fresh();
    }

    public function delete(Account $account): void
    {
        DB::connection('tenant')->transaction(static function () use ($account): void {
            $account->roleTemplates()->delete();
            $account->delete();
        });
    }

    public function restore(Account $account): Account
    {
        $account->restore();

        return $account->fresh();
    }

    public function forceDelete(Account $account): void
    {
        DB::connection('tenant')->transaction(static function () use ($account): void {
            $account->roleTemplates()->forceDelete();
            $account->forceDelete();
        });
    }

    public function attachUser(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        DB::connection('tenant')->transaction(static function () use ($account, $user, $role): void {
            $user->accountRoles()->create([
                ...$role->attributesToArray(),
                'account_id' => $account->id,
            ]);
        });
    }

    public function detachUser(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        DB::connection('tenant')->transaction(static function () use ($account, $user, $role): void {
            $embeddedRole = $user->accountRoles()
                ->where('slug', $role->slug)
                ->where('account_id', $account->id)
                ->first();

            if ($embeddedRole) {
                $embeddedRole->delete();
                $user->save();
            }
        });
    }

    private function paginateAccounts(MongoBuilder $query, bool $includeArchived, int $perPage): LengthAwarePaginator
    {
        return $query
            ->when($includeArchived, static fn (MongoBuilder $builder) => $builder->withTrashed())
            ->paginate($perPage)
            ->through(static fn (Account $account): array => [
                'id' => (string) $account->_id,
                'name' => $account->name,
                'slug' => $account->slug,
                'document' => $account->document,
                'created_at' => $account->created_at?->toJSON(),
                'updated_at' => $account->updated_at?->toJSON(),
                'deleted_at' => $account->deleted_at?->toJSON(),
            ]);
    }
}
