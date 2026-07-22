<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfilePublicDiscoveryMigrationTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_public_discovery_migration_provisions_the_declared_prefix_and_feed_indexes(): void
    {
        $legacyMigration = $this->legacyPublicNameIndexMigration();
        $legacyMigration->up();
        $legacyMigration->up();
        $migration = $this->partialPublicNameIndexMigration();
        $migration->up();
        $migration->up();

        $indexes = $this->indexMap(
            DB::connection('tenant')->getDatabase()->selectCollection('account_profiles'),
        );
        $migrationSource = file_get_contents(
            base_path('database/migrations/tenants/2026_07_20_000500_rebuild_account_profile_public_name_index_as_partial.php'),
        );
        $this->assertIsString($migrationSource);

        $this->assertSame(
            [
                'visibility' => 1,
                'is_active' => 1,
                'deleted_at' => 1,
                'profile_type' => 1,
                'name_search_key' => 1,
                '_id' => 1,
            ],
            $indexes['idx_account_profiles_public_name_v1']->getKey(),
        );
        $this->assertSame(
            [
                'visibility' => 1,
                'is_active' => 1,
                'profile_type' => 1,
                'deleted_at' => 1,
                'created_at' => -1,
                '_id' => -1,
            ],
            $indexes['idx_account_profiles_public_feed_v1']->getKey(),
        );
        $this->assertSame(
            1,
            preg_match("/'collation'\\s*=>\\s*\\['locale'\\s*=>\\s*'simple'\\]/", $migrationSource),
        );
        $this->assertSame(
            ['name_search_key' => ['$exists' => true]],
            $this->documentToArray($indexes['idx_account_profiles_public_name_v1']['partialFilterExpression'] ?? null),
        );
    }

    private function legacyPublicNameIndexMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/tenants/2026_07_20_000300_add_account_profile_public_name_index.php');

        return $migration;
    }

    private function partialPublicNameIndexMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/tenants/2026_07_20_000500_rebuild_account_profile_public_name_index_as_partial.php');

        return $migration;
    }

    /**
     * @return array<string, \MongoDB\Model\IndexInfo>
     */
    private function indexMap(\MongoDB\Collection $collection): array
    {
        $indexes = [];
        foreach ($collection->listIndexes() as $index) {
            $indexes[$index->getName()] = $index;
        }

        return $indexes;
    }

    /** @return array<string, mixed> */
    private function documentToArray(mixed $document): array
    {
        if (is_array($document)) {
            return $document;
        }

        if ($document instanceof BSONDocument) {
            return $document->getArrayCopy();
        }

        if ($document instanceof BSONArray) {
            return $document->getArrayCopy();
        }

        if ($document instanceof \Traversable) {
            return iterator_to_array($document);
        }

        return [];
    }

    private function initializeSystem(): void
    {
        $this->app->make(SystemInitializationService::class)->initialize(
            new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
                themeDataSettings: [
                    'brightness_default' => 'light',
                    'primary_seed_color' => '#fff',
                    'secondary_seed_color' => '#000',
                ],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['tenant-zeta.test'],
            ),
        );
    }
}
