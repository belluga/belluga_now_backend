<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileNestedPublicMembersProjectionService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileNestedPublicMembersProjectionBackfillCommandTest extends TestCaseTenant
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

        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)
            ->deleteMany([]);
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_projection_checkpoints')
            ->deleteMany([]);

        AccountProfile::query()->delete();
        Account::query()->delete();
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'hidden_guest',
            'label' => 'Hidden Guest',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_publicly_discoverable' => false,
                'is_favoritable' => false,
                'has_nested_profile_groups' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => true,
                'has_nested_profile_groups' => true,
            ],
        ]);
    }

    public function test_backfill_command_resets_stale_projection_state_and_rebuilds_current_rows(): void
    {
        $parentAccount = Account::create([
            'name' => 'Projection Backfill Parent Account',
            'slug' => 'projection-backfill-parent-account',
            'document' => 'DOC-PROJECTION-BACKFILL-PARENT-'.uniqid(),
        ])->fresh();
        $visibleAccount = Account::create([
            'name' => 'Projection Backfill Visible Account',
            'slug' => 'projection-backfill-visible-account',
            'document' => 'DOC-PROJECTION-BACKFILL-VISIBLE-'.uniqid(),
        ])->fresh();
        $hiddenAccount = Account::create([
            'name' => 'Projection Backfill Hidden Account',
            'slug' => 'projection-backfill-hidden-account',
            'document' => 'DOC-PROJECTION-BACKFILL-HIDDEN-'.uniqid(),
        ])->fresh();

        $visibleMember = AccountProfile::create([
            'account_id' => (string) $visibleAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Visible Member',
            'slug' => 'visible-member',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $hiddenMember = AccountProfile::create([
            'account_id' => (string) $hiddenAccount->_id,
            'profile_type' => 'hidden_guest',
            'display_name' => 'Hidden Member',
            'slug' => 'hidden-member',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $parent = AccountProfile::create([
            'account_id' => (string) $parentAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Projection Parent',
            'slug' => 'projection-parent',
            'is_active' => true,
            'visibility' => 'public',
            'aggregate_revision' => 4,
            'nested_profile_groups' => [[
                'id' => 'parceiros',
                'label' => 'Parceiros',
                'order' => 0,
                'account_profile_ids' => [
                    (string) $visibleMember->_id,
                    (string) $hiddenMember->_id,
                ],
            ]],
        ])->fresh();

        $database = DB::connection('tenant')->getDatabase();
        $database->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)->insertMany([
            [
                '_id' => 'stale-row',
                'tenant_id' => (string) Tenant::current()?->getKey(),
                'parent_profile_id' => 'stale-parent',
                'doc_type' => 'member_edge',
            ],
        ]);
        $database->selectCollection('account_profile_projection_checkpoints')->insertOne([
            '_id' => 'nested_public_members:stale-parent',
            'consumer_id' => 'nested_public_members',
            'profile_id' => 'stale-parent',
            'aggregate_revision' => 99,
            'operation_rank' => 1,
        ]);

        $exitCode = Artisan::call('account-profiles:nested-public-members-projection:backfill');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"processed_profiles": 3', $output);
        $this->assertStringContainsString('"projected_rows": 2', $output);

        $rows = iterator_to_array(
            $database
                ->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)
                ->find([], ['sort' => ['_id' => 1]]),
        );

        $this->assertCount(2, $rows);
        $this->assertSame(
            [
                'edge:'.(string) $parent->_id.':parceiros:'.(string) $visibleMember->_id,
                'head:'.(string) $parent->_id.':parceiros',
            ],
            array_map(static fn (array|object $row): string => (string) ($row['_id'] ?? ''), $rows),
        );
        $this->assertSame(
            0,
            $database
                ->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)
                ->countDocuments(['_id' => 'stale-row']),
        );
        $this->assertSame(
            0,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'nested_public_members:stale-parent']),
        );
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
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
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }
}
