<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;

class TenantAppDomainResolverService
{
    public function __construct(private readonly TenantRequestLifecycleTrace $lifecycleTrace) {}

    public function findTenantByIdentifier(string $identifier): ?Tenant
    {
        $normalized = $this->normalize($identifier);
        if ($normalized === null) {
            $this->lifecycleTrace->record('resolver.app_identifier.primary.miss');

            return null;
        }

        $this->lifecycleTrace->record('resolver.app_identifier.primary.started', [
            'app_domain_hash' => $this->lifecycleTrace->redactIdentifier($normalized),
        ]);

        $domain = Domains::query()
            ->where('path', $normalized)
            ->whereIn('type', [
                Tenant::DOMAIN_TYPE_APP_ANDROID,
                Tenant::DOMAIN_TYPE_APP_IOS,
            ])
            ->first();
        if ($domain !== null) {
            $tenant = $domain->tenant;

            $this->lifecycleTrace->record('resolver.app_identifier.primary.resolved', [
                'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
            ]);

            return $tenant;
        }

        $this->lifecycleTrace->record('resolver.app_identifier.primary.miss', [
            'app_domain_hash' => $this->lifecycleTrace->redactIdentifier($normalized),
        ]);
        $this->lifecycleTrace->record('resolver.app_identifier.fallback.started', [
            'app_domain_hash' => $this->lifecycleTrace->redactIdentifier($normalized),
        ]);

        $tenant = Tenant::query()
            ->where('app_domains', 'all', [$normalized])
            ->first();

        $this->lifecycleTrace->record(
            $tenant !== null ? 'resolver.app_identifier.fallback.resolved' : 'resolver.app_identifier.fallback.miss',
            [
                'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
            ],
        );

        return $tenant;
    }

    public function hasIdentifierForPlatform(Tenant $tenant, string $platform): bool
    {
        return $tenant->appDomainIdentifierForPlatform($platform) !== null;
    }

    private function normalize(string $raw): ?string
    {
        $normalized = Str::lower(trim($raw));

        return $normalized === '' ? null : $normalized;
    }
}
