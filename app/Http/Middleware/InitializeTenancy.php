<?php

namespace App\Http\Middleware;

use App\Actions\DomainTenantFinder;
use Closure;

class InitializeTenancy
{
    public function __construct(private readonly DomainTenantFinder $tenantFinder)
    {
    }

    public function handle($request, Closure $next)
    {

        $tenant = $this->tenantFinder->findForRequest($request);

        if($tenant){
            $tenant->makeCurrent();
        }

        return $next($request);
    }
}
