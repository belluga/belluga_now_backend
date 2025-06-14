<?php

namespace App\Http\Middleware;

use Closure;

class InitializeTenancy
{
    public function handle($request, Closure $next)
    {
        $tenant_find_class = config('multitenancy.tenant_finder');

        $tenant = new $tenant_find_class()->findForRequest($request);
        $tenant->makeCurrent();

        return $next($request);
    }
}
