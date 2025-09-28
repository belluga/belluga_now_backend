<?php

namespace Tests\Api\Traits;

use Illuminate\Support\Facades\Artisan;

trait MigrateFreshSeedOnce
{

    protected static bool $migrationHasRunOnce = false;

    protected function migrateOnce(): void
    {
        if (!static::$migrationHasRunOnce) {

            Artisan::call('tenants:artisan "migrate:fresh --database=tenant --path=database/migrations/tenants"');
            Artisan::call('migrate:fresh --database=landlord --path=database/migrations/landlord');

            static::$migrationHasRunOnce = true;
        }
    }
}
