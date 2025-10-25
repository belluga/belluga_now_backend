<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Artisan;

trait RefreshLandlordAndTenantDatabases
{
    protected function refreshLandlordAndTenantDatabases(): void
    {
        Artisan::call('migrate:fresh', [
            '--database' => 'landlord',
            '--path' => 'database/migrations/landlord',
        ]);

        Artisan::call('tenants:artisan "migrate:fresh --database=tenant --path=database/migrations/tenants"');
    }
}
