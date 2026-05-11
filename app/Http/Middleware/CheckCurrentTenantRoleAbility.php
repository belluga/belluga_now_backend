<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class CheckCurrentTenantRoleAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities)
    {
        $principal = $request->user('sanctum');

        if (! $principal instanceof LandlordUser) {
            throw new AuthorizationException('Tenant admin ability requires a landlord principal.');
        }

        $tenant = Tenant::current();
        if (! $tenant instanceof Tenant) {
            throw new AuthorizationException('Tenant context is required for tenant admin ability checks.');
        }

        $permissions = $principal->getPermissions($tenant);
        foreach ($abilities as $ability) {
            if (! $this->hasPermission($permissions, $ability)) {
                throw new AuthorizationException;
            }
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasPermission(array $permissions, string $ability): bool
    {
        if (in_array('*', $permissions, true) || in_array($ability, $permissions, true)) {
            return true;
        }

        [$resource] = explode(':', $ability, 2);

        return in_array("{$resource}:*", $permissions, true);
    }
}
