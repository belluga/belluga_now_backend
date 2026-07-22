<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileNestedPublicMembersProjectionMigrationTest extends TestCaseTenant
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

    public function test_nested_public_members_projection_migration_provisions_declared_indexes(): void
    {
        $migration = $this->projectionMigration();
        $migration->up();
        $migration->up();

        $indexes = $this->indexMap(
            DB::connection('tenant')->getDatabase()->selectCollection('account_profile_nested_public_member_projection'),
        );
        $migrationSource = file_get_contents(
            base_path('database/migrations/tenants/2026_07_20_000400_create_account_profile_nested_public_member_projection_indexes.php'),
        );
        $this->assertIsString($migrationSource);

        $this->assertSame(
            ['tenant_id' => 1, 'parent_slug' => 1, 'group_id' => 1, 'doc_type_rank' => 1, 'raw_position' => 1, '_id' => 1],
            $indexes['idx_nested_public_projection_slug_group_page_v1']->getKey(),
        );
        $this->assertSame(
            ['tenant_id' => 1, 'parent_profile_id' => 1, 'group_id' => 1, 'doc_type_rank' => 1, 'raw_position' => 1, '_id' => 1],
            $indexes['idx_nested_public_projection_parent_group_page_v1']->getKey(),
        );
        $this->assertSame(
            ['tenant_id' => 1, 'member_profile_id' => 1, 'doc_type_rank' => 1, '_id' => 1],
            $indexes['idx_nested_public_projection_member_refresh_v1']->getKey(),
        );
        $this->assertSame(
            ['tenant_id' => 1, 'parent_profile_type' => 1, 'doc_type_rank' => 1, '_id' => 1],
            $indexes['idx_nested_public_projection_parent_type_refresh_v1']->getKey(),
        );
        $this->assertSame(
            ['tenant_id' => 1, 'member_profile_type' => 1, 'doc_type_rank' => 1, '_id' => 1],
            $indexes['idx_nested_public_projection_member_type_refresh_v1']->getKey(),
        );
        $this->assertSame(
            1,
            preg_match("/'partialFilterExpression'\\s*=>\\s*\\['doc_type'\\s*=>\\s*'member_edge'\\]/", $migrationSource),
        );
    }

    private function projectionMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/tenants/2026_07_20_000400_create_account_profile_nested_public_member_projection_indexes.php');

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
