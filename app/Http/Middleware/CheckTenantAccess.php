<?php

namespace App\Http\Middleware;

use App\Models\Tenants\AccountUser;
use App\Models\Landlord\LandlordUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\PersonalAccessToken;

class CheckTenantAccess
{
    protected ?string $current_tenant_id {
        get {
            return Context::get('tenantId');
        }
    }

    protected $user {
        get {
            return auth('sanctum')->user();
        }
    }

    public function handle($request, Closure $next)
    {
        $principal = $this->user;

        if (! $principal) {
            throw new AuthenticationException;
        }

        // Tenant-scoped users are persisted only inside the active tenant DB.
        // If Sanctum resolved an AccountUser, tenancy resolution already
        // guarantees principal -> tenant affinity.
        if ($this->isTenantScopedPrincipal($principal)) {
            $this->assertAccountUserTokenMatchesCurrentTenant($principal);

            return $next($request);
        }

        if (! $principal instanceof LandlordUser) {
            throw new AuthorizationException('Unsupported principal type for tenant access.');
        }

        $hasAccess = $this->current_tenant_id
            && in_array($this->current_tenant_id, $principal->getAccessToIds(), true);

        if (! $hasAccess) {
            throw new AuthorizationException;
        }

        return $next($request);
    }

    private function isTenantScopedPrincipal(mixed $principal): bool
    {
        return $principal instanceof AccountUser;
    }

    private function assertAccountUserTokenMatchesCurrentTenant(AccountUser $user): void
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        $tokenTenantId = trim((string) ($token->getAttribute('tenant_id') ?? ''));
        if ($tokenTenantId === '') {
            return;
        }

        $currentTenantId = trim((string) ($this->current_tenant_id ?? ''));
        if ($currentTenantId === '' || ! hash_equals($tokenTenantId, $currentTenantId)) {
            throw new AuthorizationException('Account token is not valid for the current tenant.');
        }
    }
}
