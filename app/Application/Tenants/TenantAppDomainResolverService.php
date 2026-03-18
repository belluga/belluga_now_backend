<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;

class TenantAppDomainResolverService
{
    public function findTenantByIdentifier(string $identifier): ?Tenant
    {
        $normalized = $this->normalize($identifier);
        if ($normalized === null) {
            return null;
        }

        $domain = Domains::query()
            ->where('path', $normalized)
            ->whereIn('type', [
                Tenant::DOMAIN_TYPE_APP_ANDROID,
                Tenant::DOMAIN_TYPE_APP_IOS,
            ])
            ->first();
        if ($domain !== null) {
            return $domain->tenant;
        }

        return Tenant::query()
            ->where('app_domains', 'all', [$normalized])
            ->first();
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
