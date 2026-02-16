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
use MongoDB\Driver\Exception\BulkWriteException;

class AccountManagementService
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService,
        private readonly AccountOwnershipStateService $ownershipStateService
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
                $ownershipIntent = $this->resolveOwnershipIntent($payload);
                $payload = $this->applyOwnershipIntent($payload, $ownershipIntent);
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
     */
    private function resolveOwnershipIntent(array $payload): string
    {
        $rawValue = $payload['ownership_state'] ?? null;
        $intent = is_string($rawValue)
            ? $this->ownershipStateService->normalize($rawValue)
            : null;

        if (
            $intent === null ||
            ! in_array($intent, AccountOwnershipStateService::allowedCreateIntents(), true)
        ) {
            throw ValidationException::withMessages([
                'ownership_state' => [
                    'ownership_state must be tenant_owned or unmanaged.',
                ],
            ]);
        }

        return $intent;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyOwnershipIntent(array $payload, string $intent): array
    {
        unset($payload['ownership_state']);

        $payload['ownership_state'] = $intent;

        if ($intent === AccountOwnershipStateService::UNMANAGED) {
            unset($payload['organization_id']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Account $account, array $attributes): Account
    {
        try {
            $account->fill($attributes);
            $account->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Account slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account' => ['Something went wrong when trying to update the account.'],
            ]);
        }

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
