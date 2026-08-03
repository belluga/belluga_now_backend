<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Models\Landlord\Tenant;
use Belluga\Settings\Contracts\TenantScopeContextContract;
use Illuminate\Support\Facades\DB;

class TenantScopeContextAdapter implements TenantScopeContextContract
{
    public function runForTenantSlug(string $tenantSlug, callable $callback): mixed
    {
        $previousTenant = Tenant::current();
        $previousDefaultConnection = DB::getDefaultConnection();
        $tenantConnectionName = (string) config('multitenancy.tenant_database_connection_name', 'tenant');
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        $tenant->makeCurrent();

        if (
            $previousTenant instanceof Tenant
            && (string) $previousTenant->getKey() === (string) $tenant->getKey()
        ) {
            DB::setDefaultConnection($tenantConnectionName);
        }

        try {
            return $callback();
        } finally {
            if ($previousTenant instanceof Tenant) {
                $previousTenant->makeCurrent();
            } else {
                $tenant->forgetCurrent();
            }

            DB::setDefaultConnection($previousDefaultConnection);
        }
    }
}
