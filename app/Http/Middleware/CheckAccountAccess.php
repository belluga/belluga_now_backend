<?php

namespace App\Http\Middleware;

use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Auth;
use MongoDB\BSON\ObjectId;

class CheckAccountAccess
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
            return Auth::user();
        }
    }

    protected bool $have_access = false;

    public function handle($request, Closure $next)
    {
        switch (get_class($this->user)){
            case \App\Models\Landlord\LandlordUser::class:
                $this->have_access = $this->checkUserAccess($this->current_tenant_id);
                break;
            case \App\Models\Tenants\AccountUser::class:
                $this->have_access = $this->checkUserAccess($this->current_account_id);
                break;
        }

        if(!$this->have_access){
            abort(401, "You don't have access to this account");
        }

        return $next($request);
    }

    protected function checkUserAccess(string $checkId): bool {
        return in_array(new ObjectId($checkId), $this->user->getAccessToIds());
    }
}
