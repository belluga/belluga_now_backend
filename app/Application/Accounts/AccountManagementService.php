<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountManagementService
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService
    ) {
    }

    public function paginateForUser(
        AccountUser|LandlordUser $user,
        bool $includeArchived,
        int $perPage = 15,
        array $queryParams = []
    ): LengthAwarePaginator
    {
        return $this->accountQueryService->paginateForUser(
            $user,
            $queryParams,
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
                $payload = $this->applyDefaultOrganization($payload);
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyDefaultOrganization(array $payload): array
    {
        $createdByType = $payload['created_by_type'] ?? null;

        if (! empty($payload['organization_id']) || $createdByType !== 'landlord') {
            return $payload;
        }

        $tenant = Tenant::current();
        if ($tenant && ! empty($tenant->organization_id)) {
            $payload['organization_id'] = (string) $tenant->organization_id;
        }

        return $payload;
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

}
