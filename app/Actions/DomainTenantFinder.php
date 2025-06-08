<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class DomainTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
        if($this->isRequestFromSubdomain()){
            return $this->findTenantBySubdomain();
        }

        if($this->isRequestFromApp()){
            return $this->findTenantByAppDomain();
        }

        return $this->findTenantByWebDomain();
    }

    protected function findTenantByAppDomain(): ?IsTenant
    {
        $appDomain = request()->header('X-App-Domain');
        return app(IsTenant::class)::where('app_domains', 'all', [$appDomain])->first();
    }

    protected function findTenantByWebDomain(): ?IsTenant
    {
        $domain = request()->getHost();
        return app(IsTenant::class)::where('domains', 'all', [$domain])->first();
    }

    protected function findTenantBySubdomain(): ?IsTenant
    {
        $parts_request = explode('.', request()->getHost());
        $subdomain = $parts_request[0];

        return app(IsTenant::class)::where('subdomain', $subdomain)->firstOrFail();
    }

    protected function isRequestFromApp(): bool {
        return request()->hasHeader('X-App-Domain');
    }

    protected function isRequestFromSubdomain(): bool {
        $host = request()->getHost();
        $parts_request = explode('.', $host);

        if (count($parts_request) >= 2) {
            $parts_config = explode('://', config('app.url'));
            return $parts_request[1] === $parts_config[1];
        }

        return false;
    }
}
