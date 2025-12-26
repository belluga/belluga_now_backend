<?php

namespace Tests\Api\Traits;

use Illuminate\Support\Facades\Artisan;

trait MigrateFreshSeedOnce
{

    protected static bool $migrationHasRunOnce = false;

    protected function migrationCommand(): string
    {
        $landlordDsn = (string) env('DB_URI_LANDLORD', '');
        $tenantDsn = (string) env('DB_URI_TENANTS', '');
        $dsn = $landlordDsn !== '' ? $landlordDsn : $tenantDsn;

        // Atlas drops can hang or fail; prefer non-destructive migrate in that case.
        if ($dsn !== '' && str_contains($dsn, 'mongodb+srv://')) {
            return 'migrate';
        }

        return 'migrate:fresh';
    }

    protected function migrateOnce(): void
    {
        if (!static::$migrationHasRunOnce) {

            $command = $this->migrationCommand();

            Artisan::call(sprintf(
                'tenants:artisan "%s --database=tenant --path=database/migrations/tenants"',
                $command
            ));
            Artisan::call(sprintf(
                '%s --database=landlord --path=database/migrations/landlord',
                $command
            ));

            static::$migrationHasRunOnce = true;
        }
    }
}
