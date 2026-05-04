<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountManagementService
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService,
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly MapPoiProjectionService $mapPoiProjectionService,
    ) {}

    public function paginateForUser(
        AccountUser|LandlordUser $user,
        bool $includeArchived,
        int $perPage = 15,
        array $queryParams = []
    ): LengthAwarePaginator {
        return $this->accountQueryService->paginateForUser(
            $user,
            $queryParams,
            $includeArchived,
            $perPage
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, role: AccountRoleTemplate}
     */
    public function create(array $payload): array
    {
        try {
            return DB::connection('tenant')->transaction(
                fn (): array => $this->createWithinCurrentTransaction($payload)
            );
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
     * Create account + default admin role in the current tenant transaction boundary.
     *
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, role: AccountRoleTemplate}
     */
    public function createWithinCurrentTransaction(array $payload): array
    {
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
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $attributes
     */
    public function update(Account $account, array $attributes): Account
    {
        if (array_key_exists('ownership_state', $attributes)) {
            $normalizedOwnershipState = $this->ownershipStateService->normalize(
                is_string($attributes['ownership_state'])
                    ? $attributes['ownership_state']
                    : null
            );
            if (
                $normalizedOwnershipState === null ||
                ! in_array($normalizedOwnershipState, AccountOwnershipStateService::allowedCreateIntents(), true)
            ) {
                throw ValidationException::withMessages([
                    'ownership_state' => ['ownership_state must be tenant_owned or unmanaged.'],
                ]);
            }
            $attributes['ownership_state'] = $normalizedOwnershipState;
            if ($normalizedOwnershipState === AccountOwnershipStateService::UNMANAGED) {
                $attributes['organization_id'] = null;
            }
        }

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
        $this->assertUnmanagedAccountForDelete($account);
        $tenantConnection = DB::connection('tenant');

        $tenantConnection->transaction(function () use ($account, $tenantConnection): void {
            $profileIds = $this->allAccountProfileIds($account);

            $account->accountProfiles()->delete();
            $account->roleTemplates()->delete();
            $account->delete();

            $tenantConnection->afterCommit(
                fn (): bool => $this->deleteMapPoiProjections($profileIds)
            );
        });
    }

    public function restore(Account $account): Account
    {
        $account->restore();

        return $account->fresh();
    }

    public function forceDelete(Account $account): void
    {
        $this->assertUnmanagedAccountForDelete($account);
        $tenantConnection = DB::connection('tenant');

        $tenantConnection->transaction(function () use ($account, $tenantConnection): void {
            $profileIds = $this->allAccountProfileIds($account);

            $account->accountProfiles()->withTrashed()->forceDelete();
            $account->roleTemplates()->withTrashed()->forceDelete();
            $account->forceDelete();

            $tenantConnection->afterCommit(
                fn (): bool => $this->deleteMapPoiProjections($profileIds)
            );
        });
    }

    private function assertUnmanagedAccountForDelete(Account $account): void
    {
        $ownershipState = $this->ownershipStateService->deriveOwnershipState($account);
        if ($ownershipState === AccountOwnershipStateService::UNMANAGED) {
            return;
        }

        throw ValidationException::withMessages([
            'account' => ['Only unmanaged accounts can be deleted.'],
        ]);
    }

    public function attachUser(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        DB::connection('tenant')->transaction(function () use ($account, $user, $role): void {
            $this->attachUserWithinCurrentTransaction($account, $user, $role);
        });
    }

    public function attachUserWithinCurrentTransaction(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        $user->accountRoles()->create([
            ...$role->attributesToArray(),
            'account_id' => $account->id,
        ]);
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

    /**
     * @return array<int, string>
     */
    private function allAccountProfileIds(Account $account): array
    {
        return AccountProfile::query()
            ->withTrashed()
            ->where('account_id', (string) $account->_id)
            ->get(['_id'])
            ->map(static fn (AccountProfile $profile): string => trim((string) $profile->_id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function deleteMapPoiProjections(array $profileIds): bool
    {
        $this->mapPoiProjectionService->deleteByRefs('account_profile', $profileIds);

        return true;
    }
}
