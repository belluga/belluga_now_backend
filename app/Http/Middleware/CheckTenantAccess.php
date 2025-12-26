<?php

namespace App\Http\Middleware;

use App\Models\Landlord\LandlordUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Context;

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
        if (!$this->user) {
            throw new AuthenticationException();
        }

        if (! $this->user instanceof LandlordUser) {
            return $next($request);
        }

        $hasAccess = $this->current_tenant_id
            && in_array($this->current_tenant_id, $this->user->getAccessToIds(), true);

        if (!$hasAccess) {
            throw new AuthorizationException();
        }

        return $next($request);
    }
}
