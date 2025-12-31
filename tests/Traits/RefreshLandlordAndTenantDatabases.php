<?php

namespace Tests\Traits;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait RefreshLandlordAndTenantDatabases
{
    protected static bool $migrationsRan = false;
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
        $tenantDatabase = DB::connection('tenant')->getDatabase();

        $landlordCollections = iterator_to_array($landlordDatabase->listCollectionNames());
        $tenantCollections = iterator_to_array($tenantDatabase->listCollectionNames());

        if (
            static::$migrationsRan
            && empty($landlordCollections)
            && empty($tenantCollections)
            && empty($tenantDatabaseNames)
        ) {
            return;
        }

        if (! empty($landlordCollections)) {
            foreach ($landlordCollections as $collectionName) {
                $landlordDatabase->selectCollection($collectionName)->deleteMany([]);
            }
        }

        if (! empty($tenantCollections)) {
            foreach ($tenantCollections as $collectionName) {
                $tenantDatabase->selectCollection($collectionName)->deleteMany([]);
            }
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

        if (static::$migrationsRan) {
            return;
        }

        $command = $this->migrationCommand();
        $tenantPaths = $this->tenantMigrationPathArgs();

        Artisan::call($command, [
            '--database' => 'landlord',
            '--path' => 'database/migrations/landlord',
        ]);

        Artisan::call(sprintf(
            'tenants:artisan "%s --database=tenant %s"',
            $command,
            $tenantPaths
        ));

        static::$migrationsRan = true;
    }

    protected function tenantMigrationPathArgs(): string
    {
        $paths = (array) config('multitenancy.tenant_migration_paths', ['database/migrations/tenants']);

        return implode(' ', array_map(
            static fn (string $path): string => sprintf('--path=%s', $path),
            $paths
        ));
    }
}
