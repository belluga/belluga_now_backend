<?php

declare(strict_types=1);

namespace App\Integration\Media;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;

final class TenantSlugMediaScopeResolverAdapter implements TenantMediaScopeResolverContract
{
    public function __construct(
        private readonly TenantDomainResolverService $tenantDomainResolver,
    ) {}

    public function resolveTenantScope(?string $baseUrl): ?string
    {
        $host = $this->resolveHost($baseUrl);
        if ($host !== null) {
            $tenant = $this->tenantDomainResolver->findTenantByDomain($host);
            if ($tenant !== null) {
                return $tenant->slug;
            }

            $subdomainTenant = $this->resolveTenantBySubdomain($host);
            if ($subdomainTenant !== null) {
                return $subdomainTenant->slug;
            }
        }

        return Tenant::current()?->slug;
    }

    private function resolveHost(?string $baseUrl): ?string
    {
        $fromBaseUrl = $this->extractHost($baseUrl);
        if ($fromBaseUrl !== null) {
            return $fromBaseUrl;
        }

        return $this->extractHost(request()->getSchemeAndHttpHost());
    }

    private function extractHost(?string $baseUrl): ?string
    {
        $value = is_string($baseUrl) ? trim($baseUrl) : '';
        if ($value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(trim($host));
    }

    private function resolveTenantBySubdomain(string $host): ?Tenant
    {
        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost === '' || filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $parts = explode('.', $normalizedHost);
        $subdomain = trim($parts[0] ?? '');
        if ($subdomain === '') {
            return null;
        }

        return Tenant::query()->where('subdomain', $subdomain)->first();
    }
}
