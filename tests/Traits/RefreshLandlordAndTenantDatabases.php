<?php

namespace Tests\Traits;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait RefreshLandlordAndTenantDatabases
{
    protected static bool $migrationsRan = false;

    protected function prepareAuthenticatedHarnessState(): void
    {
        if (! property_exists(static::class, 'bootstrapped')) {
            return;
        }

        if (static::$bootstrapped) {
            return;
        }

        if (! method_exists($this, 'initializeSystem')) {
            return;
        }

        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();
        static::$bootstrapped = true;
    }

    protected function migrationCommand(): string
    {
        $landlordDsn = (string) env('DB_URI_LANDLORD', '');
        $tenantDsn = (string) env('DB_URI_TENANTS', '');
        $dsn = $landlordDsn !== '' ? $landlordDsn : $tenantDsn;

        // Avoid migrate:fresh on Mongo; database drops can race subsequent migrations.
        if ($dsn !== '' && str_contains($dsn, 'mongodb')) {
            return 'migrate';
        }

        return 'migrate:fresh';
    }

    protected function refreshLandlordAndTenantDatabases(): void
    {
        if (property_exists(static::class, 'bootstrapped')) {
            static::$bootstrapped = false;
        }

        $this->resetRuntimeState();

        $landlordDatabase = DB::connection('landlord')->getDatabase();
        $tenantDatabase = DB::connection('tenant')->getDatabase();
        $tenantDatabaseNames = $this->tenantDatabaseNamesForRefresh();
        $landlordDsn = (string) env('DB_URI_LANDLORD', '');
        $tenantDsn = (string) env('DB_URI_TENANTS', '');
        $dsn = $landlordDsn !== '' ? $landlordDsn : $tenantDsn;
        $isMongo = $dsn !== '' && str_contains($dsn, 'mongodb');
        if ($isMongo) {
            // Rebuild Mongo test structure from migrations on every refresh, but
            // wait for collection drops to settle before rerunning the migrator.
            $this->wipeMongoCollectionsForRefresh($landlordDatabase, $tenantDatabase, $tenantDatabaseNames, false);
            LandlordUser::withTrashed()->forceDelete();
            Landlord::query()->delete();
            Tenant::withTrashed()->forceDelete();
            static::$migrationsRan = false;
        } else {
            $landlordDatabase->drop();
            $tenantDatabase->drop();

            if (! empty($tenantDatabaseNames)) {
                $tenantClient = DB::connection('tenant')->getMongoClient();

                foreach ($tenantDatabaseNames as $databaseName) {
                    $tenantClient->selectDatabase($databaseName)->drop();
                }
            }

            static::$migrationsRan = false;
        }

        $this->resetRuntimeState();

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

        LandlordUser::withTrashed()->forceDelete();
        Landlord::query()->delete();
        Tenant::withTrashed()->forceDelete();

        $this->resetRuntimeState();

        static::$migrationsRan = true;
    }

    private function resetRuntimeState(): void
    {
        Tenant::forgetCurrent();
        Account::current()?->forget();

        Context::forget((string) config('multitenancy.current_tenant_context_key', 'tenantId'));
        Context::forget('accountId');

        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key', 'currentTenant'));
        app()->forgetInstance('currentAccount');

        Landlord::forgetSingletonCache();
        Log::withoutContext();
        $this->resetGlobalLabelState();
    }

    private function resetGlobalLabelState(): void
    {
        global $params;

        $params = [];
    }

    /**
     * @param  array<int, string>  $tenantDatabaseNames
     */
    private function wipeMongoCollectionsForRefresh(
        object $landlordDatabase,
        object $tenantDatabase,
        array $tenantDatabaseNames,
        bool $deleteOnly
    ): void {
        $this->wipeMongoDatabaseCollectionsForRefresh($landlordDatabase, $deleteOnly);
        $this->wipeMongoDatabaseCollectionsForRefresh($tenantDatabase, $deleteOnly);

        if ($tenantDatabaseNames === []) {
            return;
        }

        $tenantClient = DB::connection('tenant')->getMongoClient();

        foreach ($tenantDatabaseNames as $databaseName) {
            $this->wipeMongoDatabaseCollectionsForRefresh(
                $tenantClient->selectDatabase($databaseName),
                $deleteOnly
            );
        }
    }

    private function wipeMongoDatabaseCollectionsForRefresh(object $database, bool $deleteOnly): void
    {
        $collectionNames = array_values(iterator_to_array($database->listCollectionNames()));

        foreach ($collectionNames as $collectionName) {
            if ($deleteOnly) {
                $database->selectCollection($collectionName)->deleteMany([]);

                continue;
            }

            $database->dropCollection($collectionName);
            $this->waitForMongoCollectionDrop($database, $collectionName);
        }
    }

    private function waitForMongoCollectionDrop(object $database, string $collectionName): void
    {
        $attemptsRemaining = 50;

        while ($attemptsRemaining-- > 0) {
            $existingCollections = array_values(iterator_to_array($database->listCollectionNames()));

            if (! in_array($collectionName, $existingCollections, true)) {
                return;
            }

            usleep(100_000);
        }

        throw new \RuntimeException(sprintf(
            'Timed out waiting for Mongo collection [%s] to drop during test refresh.',
            $collectionName
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function tenantDatabaseNamesForRefresh(): array
    {
        $tenantClient = DB::connection('tenant')->getMongoClient();
        $tenantPrefix = Tenant::tenantDatabasePrefix();

        $names = Tenant::query()
            ->withTrashed()
            ->pluck('database')
            ->filter()
            ->map(static fn ($name): string => (string) $name)
            ->values()
            ->all();

        foreach ($tenantClient->listDatabases() as $databaseInfo) {
            $databaseName = (string) $databaseInfo->getName();

            if (str_starts_with($databaseName, $tenantPrefix)) {
                $names[] = $databaseName;
            }
        }

        return array_values(array_unique($names));
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
