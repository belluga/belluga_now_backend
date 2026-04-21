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
        $this->resetRuntimeState();

        $tenantDatabaseNames = Tenant::query()
            ->pluck('database')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $landlordDatabase = DB::connection('landlord')->getDatabase();
        $tenantDatabase = DB::connection('tenant')->getDatabase();
        $landlordDsn = (string) env('DB_URI_LANDLORD', '');
        $tenantDsn = (string) env('DB_URI_TENANTS', '');
        $dsn = $landlordDsn !== '' ? $landlordDsn : $tenantDsn;
        $isAtlas = $dsn !== '' && str_contains($dsn, 'mongodb+srv://');

        Log::info('Tests: landlord collections before wipe', [
            'collections' => iterator_to_array($landlordDatabase->listCollectionNames()),
            'landlords_count' => Landlord::query()->count(),
            'tenants_count' => Tenant::query()->count(),
        ]);
        Log::info('Tests: tenant collections before wipe', [
            'collections' => iterator_to_array($tenantDatabase->listCollectionNames()),
        ]);
        if ($isAtlas) {
            foreach ($landlordDatabase->listCollectionNames() as $collectionName) {
                $landlordDatabase->selectCollection($collectionName)->deleteMany([]);
            }

            foreach ($tenantDatabase->listCollectionNames() as $collectionName) {
                $tenantDatabase->selectCollection($collectionName)->deleteMany([]);
            }

            LandlordUser::query()->forceDelete();
            Landlord::query()->delete();
            Tenant::query()->forceDelete();

            if (! empty($tenantDatabaseNames)) {
                $tenantClient = DB::connection('tenant')->getMongoClient();

                foreach ($tenantDatabaseNames as $databaseName) {
                    $database = $tenantClient->selectDatabase($databaseName);

                    foreach ($database->listCollectionNames() as $collectionName) {
                        $database->selectCollection($collectionName)->deleteMany([]);
                    }
                }
            }
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

        Log::info('Tests: landlord collections after wipe', [
            'collections' => iterator_to_array($landlordDatabase->listCollectionNames()),
            'landlords_count' => Landlord::query()->count(),
            'tenants_count' => Tenant::query()->count(),
        ]);
        Log::info('Tests: tenant collections after wipe', [
            'collections' => iterator_to_array($tenantDatabase->listCollectionNames()),
        ]);

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

        LandlordUser::query()->forceDelete();
        Landlord::query()->delete();
        Tenant::query()->forceDelete();

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
