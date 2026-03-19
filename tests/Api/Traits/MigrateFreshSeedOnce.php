<?php

namespace Tests\Api\Traits;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait MigrateFreshSeedOnce
{
    protected static bool $migrationHasRunOnce = false;

    protected function migrationCommand(): string
    {
        $landlordDsn = (string) env('DB_URI_LANDLORD', '');
        $tenantDsn = (string) env('DB_URI_TENANTS', '');
        $dsn = $landlordDsn !== '' ? $landlordDsn : $tenantDsn;

        // Avoid migrate:fresh on Mongo; dropDatabase can race with subsequent migrations.
        if ($dsn !== '' && str_contains($dsn, 'mongodb')) {
            return 'migrate';
        }

        return 'migrate:fresh';
    }

    protected function migrateOnce(): void
    {
        if (! static::$migrationHasRunOnce) {

            $command = $this->migrationCommand();
            $tenantPaths = $this->tenantMigrationPathArgs();

            if ($command === 'migrate') {
                $this->wipeMongoCollections();
            }
            if ($command === 'migrate:fresh') {
                $this->dropTenantDatabases();
            }

            Artisan::call(sprintf(
                'tenants:artisan "%s --database=tenant %s"',
                $command,
                $tenantPaths
            ));
            Artisan::call(sprintf(
                '%s --database=landlord --path=database/migrations/landlord',
                $command
            ));

            static::$migrationHasRunOnce = true;
        }
    }

    protected function tenantMigrationPathArgs(): string
    {
        $paths = (array) config('multitenancy.tenant_migration_paths', ['database/migrations/tenants']);

        return implode(' ', array_map(
            static fn (string $path): string => sprintf('--path=%s', $path),
            $paths
        ));
    }

    protected function wipeMongoCollections(): void
    {
        $tenantDatabaseNames = Tenant::query()
            ->pluck('database')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $landlordDatabase = DB::connection('landlord')->getDatabase();
        $tenantDatabase = DB::connection('tenant')->getDatabase();

        foreach ($landlordDatabase->listCollectionNames() as $collectionName) {
            $landlordDatabase->dropCollection($collectionName);
        }

        foreach ($tenantDatabase->listCollectionNames() as $collectionName) {
            $tenantDatabase->dropCollection($collectionName);
        }

        if (empty($tenantDatabaseNames)) {
            return;
        }

        $tenantClient = DB::connection('tenant')->getMongoClient();

        foreach ($tenantDatabaseNames as $databaseName) {
            $database = $tenantClient->selectDatabase($databaseName);

            foreach ($database->listCollectionNames() as $collectionName) {
                $database->dropCollection($collectionName);
            }
        }
    }

    protected function dropTenantDatabases(): void
    {
        $tenantClient = DB::connection('tenant')->getMongoClient();
        $defaultTenantDb = (string) config('database.connections.tenant.database');

        foreach ($tenantClient->listDatabases() as $databaseInfo) {
            $databaseName = $databaseInfo->getName();

            if (str_starts_with($databaseName, 'tenant_') || $databaseName === $defaultTenantDb) {
                $tenantClient->selectDatabase($databaseName)->drop();
            }
        }
    }
}
