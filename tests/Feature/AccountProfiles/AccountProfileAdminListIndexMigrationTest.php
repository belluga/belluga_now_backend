<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class AccountProfileAdminListIndexMigrationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    public function test_migration_rolls_back_safely_with_or_without_the_collection(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profiles');
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $database->createCollection('account_profiles');
        $migration->up();

        $collection = $database->selectCollection('account_profiles');
        $indexNames = collect(iterator_to_array($collection->listIndexes()))
            ->map(static fn ($index): string => $index->getName());
        self::assertContains('idx_account_profiles_admin_browse_v1', $indexNames);
        self::assertContains('idx_account_profiles_admin_name_search_v1', $indexNames);
        self::assertContains('idx_account_profiles_admin_terms_search_v1', $indexNames);

        $migration->down();

        $remainingIndexNames = collect(iterator_to_array($collection->listIndexes()))
            ->map(static fn ($index): string => $index->getName());
        self::assertNotContains('idx_account_profiles_admin_browse_v1', $remainingIndexNames);
        self::assertNotContains('idx_account_profiles_admin_name_search_v1', $remainingIndexNames);
        self::assertNotContains('idx_account_profiles_admin_terms_search_v1', $remainingIndexNames);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'database/migrations/tenants/2026_09_06_000100_add_tenant_admin_account_profile_list_indexes.php'
        );

        return $migration;
    }

    private function makeMigrationTenantCurrent(): void
    {
        $tenant = new Tenant;
        $tenant->setRawAttributes([
            '_id' => 'tenant-test',
            'slug' => 'tenant-test',
            'database' => (string) config('database.connections.tenant.database'),
        ], true);
        $tenant->exists = true;
        $tenant->makeCurrent();
    }
}
