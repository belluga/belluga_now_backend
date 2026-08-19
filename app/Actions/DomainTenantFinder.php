<?php

declare(strict_types=1);

namespace App\Actions;

use App\Application\Tenants\TenantAppDomainResolverService;
use App\Application\Tenants\TenantDomainResolverService;
use App\Application\Tenants\TenantRequestLifecycleTrace;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class DomainTenantFinder extends TenantFinder
{
    private array $local_environment_alternatives = ['localhost', '127.0.0.1', 'nginx'];

    private ?Request $activeRequest = null;

    public function __construct(
        private readonly TenantDomainResolverService $domainResolver,
        private readonly TenantAppDomainResolverService $appDomainResolver,
        private readonly TenantRequestLifecycleTrace $lifecycleTrace,
    ) {}

    public function findForRequest(Request $request): ?IsTenant
    {
        $this->activeRequest = $request;

        try {
            if ($this->isRequestFromSubdomain()) {
                $this->lifecycleTrace->record('finder.branch.subdomain');

                $tenant = $this->findTenantBySubdomain();
                if ($tenant !== null) {
                    return $tenant;
                }
            }

            if ($this->isRequestFromApp() && $this->isRequestToLandlordHost()) {
                $this->lifecycleTrace->record('finder.branch.app_domain');

                return $this->findTenantByAppDomain();
            }

            $this->lifecycleTrace->record('finder.branch.web_domain');

            return $this->findTenantByWebDomain();
        } finally {
            $this->activeRequest = null;
        }
    }

    protected function findTenantByAppDomain(): ?IsTenant
    {
        $appDomain = $this->resolveAppDomainFromRequest();

        $this->lifecycleTrace->record('resolver.app_domain.started', [
            'app_domain_hash' => $this->lifecycleTrace->redactIdentifier($appDomain),
        ]);

        if ($appDomain === null) {
            $this->lifecycleTrace->record('resolver.app_domain.miss');

            return null;
        }

        $tenant = $this->appDomainResolver->findTenantByIdentifier($appDomain);

        $this->lifecycleTrace->record(
            $tenant !== null ? 'resolver.app_domain.resolved' : 'resolver.app_domain.miss',
            [
                'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
            ],
        );

        return $tenant;
    }

    protected function findTenantByWebDomain(): ?IsTenant
    {
        $domain = $this->request()->getHost();

        return $this->domainResolver->findTenantByDomain($domain);
    }

    protected function findTenantBySubdomain(): ?IsTenant
    {
        $parts_request = explode('.', $this->request()->getHost());
        $subdomain = $parts_request[0];

        $this->lifecycleTrace->record('resolver.subdomain.started', [
            'subdomain_hash' => $this->lifecycleTrace->redactIdentifier($subdomain),
        ]);

        $tenant = app(IsTenant::class)::where('subdomain', $subdomain)->first();

        $this->lifecycleTrace->record(
            $tenant !== null ? 'resolver.subdomain.resolved' : 'resolver.subdomain.miss',
            [
                'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
            ],
        );

        return $tenant;
    }

    protected function isRequestFromApp(): bool
    {
        return $this->resolveAppDomainFromRequest() !== null;
    }

    protected function isRequestFromSubdomain(): bool
    {
        $host = $this->request()->getHost();
        $parts_request = explode('.', $host, 2);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (count($parts_request) >= 2) {
            $parts_config = explode('://', config('app.url'));
            if ($parts_request[1] === $parts_config[1]) {
                return true;
            }

            if (app()->environment('local')) {
                return ! in_array($parts_request[0], $this->local_environment_alternatives, true);
            }

            return false;
        }

        if ($this->isLocalEnvironment()) {
            return in_array($parts_request[0], $this->local_environment_alternatives);
        }

        return false;
    }

    private function isLocalEnvironment(): bool
    {
        return in_array($this->request()->getHost(), $this->local_environment_alternatives);
    }

    private function resolveAppDomainFromRequest(): ?string
    {
        $request = $this->request();

        $headerDomain = $request->header('X-App-Domain');
        if (is_string($headerDomain) && trim($headerDomain) !== '') {
            return trim($headerDomain);
        }

        $queryDomain = $request->query('app_domain');
        if (is_string($queryDomain) && trim($queryDomain) !== '') {
            return trim($queryDomain);
        }

        $inputDomain = $request->input('app_domain');
        if (is_string($inputDomain) && trim($inputDomain) !== '') {
            return trim($inputDomain);
        }

        return null;
    }

    private function isRequestToLandlordHost(): bool
    {
        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($configuredHost) || $configuredHost === '') {
            $configuredHost = (string) config('app.url');
        }

        return strcasecmp($this->request()->getHost(), trim($configuredHost)) === 0;
    }

    private function request(): Request
    {
        return $this->activeRequest ?? request();
    }
}
