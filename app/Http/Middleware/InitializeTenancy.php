<?php

namespace App\Http\Middleware;

use Closure;

class InitializeTenancy
{
    public function handle($request, Closure $next)
    {

        $tenant_find_class = config('multitenancy.tenant_finder');

        $tenant = new $tenant_find_class()->findForRequest($request);

        if(!$tenant){
            abort(400, "Tenant not found");
        }

        $tenant->makeCurrent();

        return $next($request);
    }
}
