<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

final class TenantScopedSanctum extends Sanctum
{
    public static function actingAs($user, $abilities = [], $guard = 'sanctum')
    {
        $priorTenantId = $user instanceof AccountUser
            ? trim((string) ($user->currentAccessToken()?->getAttribute('tenant_id') ?? ''))
            : '';

        $authenticated = parent::actingAs($user, $abilities, $guard);
        if (! $authenticated instanceof AccountUser) {
            return $authenticated;
        }

        $tenantId = trim((string) (Tenant::current()?->getKey() ?? $priorTenantId));
        if ($tenantId === '') {
            throw new RuntimeException('A current tenant is required for tenant-scoped Sanctum test authentication.');
        }

        $authenticated->currentAccessToken()
            ?->shouldReceive('getAttribute')
            ->with('tenant_id')
            ->andReturn($tenantId);

        return $authenticated;
    }
}
