<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;

class TenantDomainResolverService
{
    public function __construct(private readonly TenantRequestLifecycleTrace $lifecycleTrace) {}

    public function findTenantByDomain(string $host): ?Tenant
    {
        $normalized = Str::lower(trim($host));

        $this->lifecycleTrace->record('resolver.web_domain.primary.started', [
            'host_hash' => $this->lifecycleTrace->redactIdentifier($normalized),
        ]);

        $tenant = Tenant::where('domains', 'all', [$normalized])->first();
        if ($tenant !== null) {
            $this->lifecycleTrace->record('resolver.web_domain.primary.resolved', [
                'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
            ]);

            return $tenant;
        }

        $this->lifecycleTrace->record('resolver.web_domain.primary.miss');
        $this->lifecycleTrace->record('resolver.web_domain.fallback.started', [
            'host_hash' => $this->lifecycleTrace->redactIdentifier($normalized),
        ]);

        $resolvedTenant = Domains::query()
            ->where('path', $normalized)
            ->where('type', Tenant::DOMAIN_TYPE_WEB)
            ->first()?->tenant;

        $this->lifecycleTrace->record(
            $resolvedTenant !== null ? 'resolver.web_domain.fallback.resolved' : 'resolver.web_domain.fallback.miss',
            [
                'tenant_target' => $this->lifecycleTrace->tenantFingerprint($resolvedTenant),
            ],
        );

        return $resolvedTenant;
    }
}
