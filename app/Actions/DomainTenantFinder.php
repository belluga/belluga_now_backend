<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;
use App\Models\Landlord\Domains;

class DomainTenantFinder extends TenantFinder
{
    private array $local_environment_alternatives = ['localhost', '127.0.0.1', 'nginx'];

    public function findForRequest(Request $request): ?IsTenant
    {
        info('[DomainTenantFinder] host='.$request->getHost().' headers='.json_encode($request->headers->all()));
        if($this->isRequestFromSubdomain()){
            info('[DomainTenantFinder] resolving by subdomain');
            return $this->findTenantBySubdomain();
        }

        if($this->isRequestFromApp()){
            info('[DomainTenantFinder] resolving by app domain');
            return $this->findTenantByAppDomain();
        }

        info('[DomainTenantFinder] resolving by web domain');
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
        // First check inline domains array
        $tenant = app(IsTenant::class)::where('domains', 'all', [$domain])->first();

        if ($tenant !== null) {
          info('[DomainTenantFinder] found tenant via domains array: '.$tenant->id);
          return $tenant;
        }

        // Fallback: check domains collection (landlord connection)
        $domainEntry = Domains::where('path', $domain)->first();
        if ($domainEntry) {
          info('[DomainTenantFinder] found tenant via domains collection: '.$domainEntry->tenant_id);
        } else {
          info('[DomainTenantFinder] no tenant found for domain '.$domain);
        }
        return $domainEntry?->tenant;
    }

    protected function findTenantBySubdomain(): ?IsTenant
    {
        $parts_request = explode('.', request()->getHost());
        $subdomain = $parts_request[0];

        return app(IsTenant::class)::where('subdomain', $subdomain)->first();
    }

    protected function isRequestFromApp(): bool {
        return request()->hasHeader('X-App-Domain');
    }

    protected function isRequestFromSubdomain(): bool {
        $host = request()->getHost();
        $parts_request = explode('.', $host, 2);

        if (count($parts_request) >= 2) {
            $parts_config = explode('://', config('app.url'));
            return $parts_request[1] === $parts_config[1];
        }

        if($this->isLocalEnvironment()){
            return in_array($parts_request[0], $this->local_environment_alternatives);
        }

        return false;
    }

    private function isLocalEnvironment(): bool {
        return in_array(request()->getHost(), $this->local_environment_alternatives);
    }
}
