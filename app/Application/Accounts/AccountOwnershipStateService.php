<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Database\Eloquent\Builder;
use MongoDB\BSON\ObjectId;

class AccountOwnershipStateService
{
    public const TENANT_OWNED = 'tenant_owned';
    public const UNMANAGED = 'unmanaged';
    public const USER_OWNED = 'user_owned';

    /**
     * @return array<int, string>
     */
    public static function allowedStates(): array
    {
        return [
            self::TENANT_OWNED,
            self::UNMANAGED,
            self::USER_OWNED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedCreateIntents(): array
    {
        return [
            self::TENANT_OWNED,
            self::UNMANAGED,
        ];
    }

    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, self::allowedStates(), true)
            ? $normalized
            : null;
    }

    public function deriveOwnershipState(Account $account): string
    {
        $storedState = $this->normalize(
            is_string($account->ownership_state ?? null)
                ? $account->ownership_state
                : null
        );

        if ($storedState === self::TENANT_OWNED) {
            return self::TENANT_OWNED;
        }

        // Any account with an attached operator is not unmanaged.
        if ($this->hasUserOperator($account)) {
            return self::USER_OWNED;
        }

        if ($storedState === self::UNMANAGED || $storedState === self::USER_OWNED) {
            return $storedState;
        }

        if ($this->isTenantOwned($account)) {
            return self::TENANT_OWNED;
        }

        return self::UNMANAGED;
    }

    public function isTenantOwned(Account $account): bool
    {
        $tenantOrganizationId = $this->tenantOrganizationId();

        if ($tenantOrganizationId === null || empty($account->organization_id)) {
            return false;
        }

        return (string) $account->organization_id === $tenantOrganizationId;
    }

    public function hasUserOperator(Account $account): bool
    {
        return AccountUser::query()
            ->where('account_roles.account_id', (string) $account->_id)
            ->exists();
    }

    public function applyOwnershipFilterToAccountsQuery(
        Builder $query,
        ?string $ownershipState
    ): void {
        $state = $this->normalize($ownershipState);
        if ($state === null) {
            $this->applyNoResultsConstraint($query);

            return;
        }

        $tenantOrganizationId = $this->tenantOrganizationId();
        $userOwnedAccountIds = $this->userOperatedAccountIds();
        $userOwnedObjectIds = $this->toObjectIds($userOwnedAccountIds);

        if ($state === self::TENANT_OWNED) {
            $query->where(function (Builder $tenantOwnedQuery) use ($tenantOrganizationId): void {
                $tenantOwnedQuery->where('ownership_state', self::TENANT_OWNED);

                if ($tenantOrganizationId === null) {
                    return;
                }

                $tenantOwnedQuery->orWhere(function (Builder $legacyQuery) use ($tenantOrganizationId): void {
                    $this->applyMissingOwnershipStateConstraint($legacyQuery);
                    $legacyQuery->where('organization_id', $tenantOrganizationId);
                });
            });

            return;
        }

        if ($state === self::USER_OWNED) {
            if ($userOwnedObjectIds === []) {
                $query->where('ownership_state', self::USER_OWNED);

                return;
            }

            $query->where(function (Builder $userOwnedQuery) use ($userOwnedObjectIds): void {
                $userOwnedQuery->where('ownership_state', self::USER_OWNED);

                $userOwnedQuery->orWhere(function (Builder $promotedQuery) use ($userOwnedObjectIds): void {
                    $promotedQuery->whereRaw(['_id' => ['$in' => $userOwnedObjectIds]]);
                    $promotedQuery->where(function (Builder $explicitNonTenant) {
                        $explicitNonTenant
                            ->whereNull('ownership_state')
                            ->orWhere('ownership_state', '')
                            ->orWhere('ownership_state', self::UNMANAGED)
                            ->orWhere('ownership_state', self::USER_OWNED);
                    });
                });
            });

            return;
        }

        $query->where(function (Builder $unmanagedQuery) use (
            $tenantOrganizationId,
            $userOwnedObjectIds
        ): void {
            $unmanagedQuery->where('ownership_state', self::UNMANAGED);
            if ($userOwnedObjectIds !== []) {
                $unmanagedQuery->whereRaw(['_id' => ['$nin' => $userOwnedObjectIds]]);
            }

            $unmanagedQuery->orWhere(function (Builder $legacyQuery) use (
                $tenantOrganizationId,
                $userOwnedObjectIds
            ): void {
                $this->applyMissingOwnershipStateConstraint($legacyQuery);

                if ($userOwnedObjectIds !== []) {
                    $legacyQuery->whereRaw(['_id' => ['$nin' => $userOwnedObjectIds]]);
                }

                $this->applyNotTenantOwnedConstraint($legacyQuery, $tenantOrganizationId);
            });
        });
    }

    public function tenantOrganizationId(): ?string
    {
        $tenant = Tenant::current();

        if ($tenant === null || empty($tenant->organization_id)) {
            return null;
        }

        return (string) $tenant->organization_id;
    }

    /**
     * @return array<int, string>
     */
    private function userOperatedAccountIds(): array
    {
        $set = [];

        $rolesPerUser = AccountUser::query()->pluck('account_roles')->all();
        foreach ($rolesPerUser as $roles) {
            if (! is_array($roles)) {
                continue;
            }

            foreach ($roles as $role) {
                if (! is_array($role)) {
                    continue;
                }

                $accountId = $role['account_id'] ?? null;
                if (! is_string($accountId) || trim($accountId) === '') {
                    continue;
                }

                $set[$accountId] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, ObjectId>
     */
    private function toObjectIds(array $ids): array
    {
        $objectIds = [];

        foreach ($ids as $id) {
            if (! preg_match('/^[a-f0-9]{24}$/i', $id)) {
                continue;
            }

            $objectIds[] = new ObjectId($id);
        }

        return $objectIds;
    }

    private function applyNotTenantOwnedConstraint(
        Builder $query,
        ?string $tenantOrganizationId
    ): void {
        if ($tenantOrganizationId === null) {
            return;
        }

        $query->where(static function (Builder $subQuery) use ($tenantOrganizationId): void {
            $subQuery
                ->whereNull('organization_id')
                ->orWhere('organization_id', '!=', $tenantOrganizationId);
        });
    }

    private function applyNoResultsConstraint(Builder $query): void
    {
        $query->whereRaw(['_id' => ['$exists' => false]]);
    }

    private function applyMissingOwnershipStateConstraint(Builder $query): void
    {
        $query->where(static function (Builder $missingStateQuery): void {
            $missingStateQuery
                ->whereNull('ownership_state')
                ->orWhere('ownership_state', '');
        });
    }
}
