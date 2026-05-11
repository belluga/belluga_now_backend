<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

class TenantScopedAccessTokenService
{
    public const ACCOUNT_SCOPED_ABILITY_RESOURCES = [
        'account-users',
        'account-roles',
        'events',
        'push-messages',
    ];

    /**
     * @param  array<int, string>  $abilities
     */
    public function issueForAccountUser(
        AccountUser $user,
        string $tokenName,
        array $abilities,
        ?string $tenantId = null,
        ?string $accountId = null,
    ): NewAccessToken {
        $tenantId = $this->resolveTenantId($tenantId);
        if ($tenantId === null) {
            throw new RuntimeException('Cannot issue tenant-scoped account token without current tenant context.');
        }

        $accountId = $this->resolveAccountIdForAbilities($user, $abilities, $accountId);

        if (self::containsAccountScopedAbility($abilities)) {
            if ($accountId === null) {
                throw new RuntimeException('Cannot issue account-scoped account token without account context.');
            }

            $newToken = AccountUser::withValidatedAccountScopedTokenIssuerContext(
                $user,
                $accountId,
                $abilities,
                static fn (): NewAccessToken => $user->createToken($tokenName, $abilities)
            );
        } else {
            $newToken = $user->createToken($tokenName, $abilities);
        }

        $this->stampTenantId($newToken, $tenantId);
        if ($accountId !== null) {
            $this->stampAccountId($newToken, $accountId);
        }

        return $newToken;
    }

    public function stampCurrentTenantId(NewAccessToken $newToken): void
    {
        $this->stampTenantId($newToken);
    }

    public function stampTenantId(NewAccessToken $newToken, ?string $tenantId = null): void
    {
        $tenantId = $this->resolveTenantId($tenantId);
        if ($tenantId === null) {
            throw new RuntimeException('Cannot issue tenant-scoped account token without current tenant context.');
        }

        $newToken->accessToken->setAttribute('tenant_id', $tenantId);
        $newToken->accessToken->save();
    }

    private function stampAccountId(NewAccessToken $newToken, string $accountId): void
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new RuntimeException('Cannot stamp account-scoped account token without account context.');
        }

        $newToken->accessToken->setAttribute('account_id', $accountId);
        $newToken->accessToken->save();
    }

    private function resolveTenantId(?string $tenantId): ?string
    {
        $explicitTenantId = trim((string) $tenantId);
        if ($explicitTenantId !== '') {
            return $explicitTenantId;
        }

        return $this->resolveCurrentTenantId();
    }

    private function resolveCurrentTenantId(): ?string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return null;
        }

        $tenantId = trim((string) $tenant->getAttribute('_id'));

        return $tenantId !== '' ? $tenantId : null;
    }

    private function resolveAccountId(?string $accountId): ?string
    {
        $explicitAccountId = trim((string) $accountId);
        if ($explicitAccountId !== '') {
            return $explicitAccountId;
        }

        return $this->resolveCurrentAccountId();
    }

    private function resolveCurrentAccountId(): ?string
    {
        $account = Account::current();
        if ($account === null) {
            return null;
        }

        $accountId = trim((string) $account->getAttribute('_id'));

        return $accountId !== '' ? $accountId : null;
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function resolveAccountIdForAbilities(AccountUser $user, array $abilities, ?string $accountId): ?string
    {
        $requiresAccountBinding = self::containsAccountScopedAbility($abilities);
        $resolvedAccountId = $this->resolveAccountId($accountId);
        if ($resolvedAccountId !== null) {
            if ($requiresAccountBinding && ! $this->userCanAccessAccountId($user, $resolvedAccountId)) {
                throw new RuntimeException('Cannot issue account-scoped account token for inaccessible account context.');
            }

            return $resolvedAccountId;
        }

        if (! $requiresAccountBinding) {
            return null;
        }

        $safeAccountId = $this->resolveSingleAccessibleAccountId($user);
        if ($safeAccountId !== null) {
            return $safeAccountId;
        }

        throw new RuntimeException('Cannot issue account-scoped account token without account context.');
    }

    private function resolveSingleAccessibleAccountId(AccountUser $user): ?string
    {
        $accountIds = $this->normalizedAccessIds($user);

        return count($accountIds) === 1 ? $accountIds[0] : null;
    }

    private function userCanAccessAccountId(AccountUser $user, string $accountId): bool
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            return false;
        }

        foreach ($this->normalizedAccessIds($user) as $accessId) {
            if (hash_equals($accessId, $accountId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedAccessIds(AccountUser $user): array
    {
        return collect($user->getAccessToIds())
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $abilities
     */
    public static function containsAccountScopedAbility(array $abilities): bool
    {
        foreach ($abilities as $ability) {
            $ability = trim((string) $ability);
            if ($ability === '') {
                continue;
            }

            if ($ability === '*') {
                return true;
            }

            [$resource] = explode(':', $ability, 2);
            if (in_array($resource, self::ACCOUNT_SCOPED_ABILITY_RESOURCES, true)) {
                return true;
            }
        }

        return false;
    }
}
