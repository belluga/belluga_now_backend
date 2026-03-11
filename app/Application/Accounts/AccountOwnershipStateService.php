<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Database\Eloquent\Builder;

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

    /**
     * @param  array<string, bool>|null  $userOperatedAccountLookup
     */
    public function deriveOwnershipState(
        Account $account,
        ?array $userOperatedAccountLookup = null
    ): string {
        $storedState = $this->normalize(
            is_string($account->ownership_state ?? null)
                ? $account->ownership_state
                : null
        );

        if ($storedState === self::TENANT_OWNED) {
            return self::TENANT_OWNED;
        }

        // Any account with an attached operator is not unmanaged.
        if ($this->hasUserOperator($account, $userOperatedAccountLookup)) {
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

    /**
     * @param  array<string, bool>|null  $userOperatedAccountLookup
     */
    public function hasUserOperator(
        Account $account,
        ?array $userOperatedAccountLookup = null
    ): bool {
        if ($userOperatedAccountLookup !== null) {
            return array_key_exists((string) $account->_id, $userOperatedAccountLookup);
        }

        return AccountUser::query()
            ->where('account_roles.account_id', (string) $account->_id)
            ->exists();
    }

    /**
     * @param  array<int, string>|null  $candidateAccountIds
     * @return array<string, bool>
     */
    public function userOperatedAccountIdLookup(?array $candidateAccountIds = null): array
    {
        $accountIds = $this->userOperatedAccountIds();

        if ($candidateAccountIds !== null) {
            $allowed = [];
            foreach ($candidateAccountIds as $id) {
                $normalized = trim((string) $id);
                if ($normalized === '') {
                    continue;
                }
                $allowed[$normalized] = true;
            }

            $accountIds = array_values(array_filter(
                $accountIds,
                static fn (string $id): bool => array_key_exists($id, $allowed)
            ));
        }

        $lookup = [];
        foreach ($accountIds as $accountId) {
            $lookup[$accountId] = true;
        }

        return $lookup;
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
            if ($userOwnedAccountIds === []) {
                $query->where('ownership_state', self::USER_OWNED);

                return;
            }

            $query->where(function (Builder $userOwnedQuery) use ($userOwnedAccountIds): void {
                $userOwnedQuery->where('ownership_state', self::USER_OWNED);

                $userOwnedQuery->orWhere(function (Builder $promotedQuery) use ($userOwnedAccountIds): void {
                    $promotedQuery->whereIn('_id', $userOwnedAccountIds);
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
            $userOwnedAccountIds
        ): void {
            $unmanagedQuery->where(function (Builder $explicitUnmanagedQuery) use ($userOwnedAccountIds): void {
                $explicitUnmanagedQuery->where('ownership_state', self::UNMANAGED);
                if ($userOwnedAccountIds !== []) {
                    $explicitUnmanagedQuery->whereNotIn('_id', $userOwnedAccountIds);
                }
            });

            $unmanagedQuery->orWhere(function (Builder $legacyQuery) use (
                $tenantOrganizationId,
                $userOwnedAccountIds
            ): void {
                $this->applyMissingOwnershipStateConstraint($legacyQuery);

                if ($userOwnedAccountIds !== []) {
                    $legacyQuery->whereNotIn('_id', $userOwnedAccountIds);
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
