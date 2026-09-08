<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class EventAccountProfileCandidateBrowseIndexMigrationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    public function test_migration_creates_and_removes_the_candidate_browse_index(): void
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
        $indexes = collect(iterator_to_array($collection->listIndexes()))
            ->keyBy(static fn ($index): string => $index->getName());
        $index = $indexes->get('idx_account_profiles_event_candidate_browse_v1');

        self::assertNotNull($index);
        self::assertSame(
            ['deleted_at' => 1, 'profile_type' => 1, 'display_name' => 1, '_id' => 1],
            (array) $index->getKey(),
        );

        $migration->down();

        $remainingNames = collect(iterator_to_array($collection->listIndexes()))
            ->map(static fn ($remaining): string => $remaining->getName());
        self::assertNotContains('idx_account_profiles_event_candidate_browse_v1', $remainingNames);
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'database/migrations/tenants/2026_09_07_000100_add_event_account_profile_candidate_browse_index.php'
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
