<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

class TenantScopedAccessTokenService
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function issueForAccountUser(
        AccountUser $user,
        string $tokenName,
        array $abilities,
        ?string $tenantId = null,
    ): NewAccessToken {
        $newToken = $user->createToken($tokenName, $abilities);
        $this->stampTenantId($newToken, $tenantId);

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
}
