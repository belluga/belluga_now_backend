<?php

namespace Tests\Traits;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait RefreshLandlordAndTenantDatabases
{
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

    protected function refreshLandlordAndTenantDatabases(): void
    {
        $tenantDatabaseNames = Tenant::query()
            ->pluck('database')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $landlordDatabase = DB::connection('landlord')->getDatabase();
        foreach ($landlordDatabase->listCollectionNames() as $collectionName) {
            $landlordDatabase->selectCollection($collectionName)->deleteMany([]);
        }

        $tenantDatabase = DB::connection('tenant')->getDatabase();
        foreach ($tenantDatabase->listCollectionNames() as $collectionName) {
            $tenantDatabase->selectCollection($collectionName)->deleteMany([]);
        }

        if (! empty($tenantDatabaseNames)) {
            $tenantClient = DB::connection('tenant')->getMongoClient();

            foreach ($tenantDatabaseNames as $databaseName) {
                $database = $tenantClient->selectDatabase($databaseName);

                foreach ($database->listCollectionNames() as $collectionName) {
                    $database->selectCollection($collectionName)->deleteMany([]);
                }
            }
        }

        $command = $this->migrationCommand();

        Artisan::call($command, [
            '--database' => 'landlord',
            '--path' => 'database/migrations/landlord',
        ]);

        Artisan::call(sprintf(
            'tenants:artisan "%s --database=tenant --path=database/migrations/tenants"',
            $command
        ));
    }
}
