<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;

class TenantDomainResolverService
{
    public function findTenantByDomain(string $host): ?Tenant
    {
        $normalized = Str::lower(trim($host));

        $tenant = Tenant::where('domains', 'all', [$normalized])->first();
        if ($tenant !== null) {
            return $tenant;
        }

        return Domains::query()
            ->where('path', $normalized)
            ->where('type', Tenant::DOMAIN_TYPE_WEB)
            ->first()?->tenant;
    }
}
