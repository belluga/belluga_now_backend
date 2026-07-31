<?php

declare(strict_types=1);

namespace App\Integration\Push;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Illuminate\Support\Facades\DB;

class PushTenantContextAdapter implements PushTenantContextContract
{
    public function currentTenantId(): ?string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return null;
        }

        return (string) $tenant->getAttribute('_id');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
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
