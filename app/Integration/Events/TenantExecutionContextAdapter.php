<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Landlord\Tenant;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Illuminate\Support\Facades\DB;

class TenantExecutionContextAdapter implements TenantExecutionContextContract
{
    public function runForEachTenant(callable $callback): void
    {
        $previousTenant = Tenant::current();
        $previousDefaultConnection = DB::getDefaultConnection();
        $tenantConnectionName = (string) config('multitenancy.tenant_database_connection_name', 'tenant');

        try {
            Tenant::query()
                ->get()
                ->each(static function (Tenant $tenant) use ($callback, $previousTenant, $tenantConnectionName): void {
                    $tenant->makeCurrent();

                    if (
                        $previousTenant instanceof Tenant
                        && (string) $previousTenant->getKey() === (string) $tenant->getKey()
                    ) {
                        DB::setDefaultConnection($tenantConnectionName);
                    }

                    try {
                        $callback();
                    } finally {
                        $tenant->forgetCurrent();
                    }
                });
        } finally {
            if ($previousTenant instanceof Tenant) {
                $previousTenant->makeCurrent();
            }

            DB::setDefaultConnection($previousDefaultConnection);
        }
    }
}
