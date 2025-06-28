<?php

namespace App\Http\Middleware;

use App\Models\Landlord\Tenant;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Auth;

class CheckUserAccess
{

    protected string $current_account_id {
        get {
            return Context::get('accountId');
        }
    }

    protected string $current_tenant_id {
        get {
            return Context::get('tenantId');
        }
    }

    protected $user {
        get {
            return auth('sanctum')->user();
        }
    }

    protected bool $have_access = false;

    public function handle($request, Closure $next)
    {
        if(!$this->user){
            throw new AuthenticationException();
        }

        switch (get_class($this->user)){
            case \App\Models\Landlord\LandlordUser::class:
                $this->have_access = $this->checkUserAccess($this->current_tenant_id);
                break;
            case \App\Models\Tenants\AccountUser::class:
                $this->have_access = $this->checkUserAccess($this->current_account_id);
                break;
        }

        if(!$this->have_access){
            throw new AuthenticationException();
        }

        return $next($request);
    }

    protected function checkUserAccess(string $checkId): bool {
        return in_array($checkId, $this->user->getAccessToIds());
    }
}
