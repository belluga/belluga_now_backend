<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Illuminate\Support\Facades\Artisan;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountMissingProfileRepairCommandTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        InviteEdge::query()->delete();
        AccountProfile::withTrashed()->forceDelete();
        Account::withTrashed()->forceDelete();
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_publicly_discoverable' => false,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
    }

    public function test_repair_dry_run_and_execute_restore_safe_non_test_profile(): void
    {
        [$account, $profile] = $this->createCorruptedAccount('merchant-repair-restore', 'merchant-repair-restore');

        $dryRun = $this->runRepairCommand();

        $this->assertSame(1, $dryRun['totals']['invalid']);
        $this->assertSame(1, $dryRun['totals']['would_restore']);
        $this->assertSame('safe_restore', $dryRun['rows'][0]['policy_branch']);
        $this->assertNotNull(AccountProfile::onlyTrashed()->find((string) $profile->_id));

        $executed = $this->runRepairCommand(execute: true);

        $this->assertSame(1, $executed['totals']['restored']);
        $this->assertNotNull(AccountProfile::query()->find((string) $profile->_id));
        $this->assertSame(
            1,
            AccountProfile::query()
                ->where('account_id', (string) $account->_id)
                ->where('is_active', true)
                ->count()
        );
        $this->assertSame(0, $this->runRepairCommand()['totals']['invalid']);
    }

    public function test_repair_deletes_safe_known_test_seed_account_aggregate_only_in_execute_mode(): void
    {
        [$account, $profile] = $this->createCorruptedAccount('playwright-repair-seed', 'playwright-repair-seed');

        $dryRun = $this->runRepairCommand();

        $this->assertSame(1, $dryRun['totals']['would_delete_test_seed']);
        $this->assertSame('safe_test_seed_aggregate_deletion', $dryRun['rows'][0]['policy_branch']);
        $this->assertNotNull(Account::query()->find((string) $account->_id));
        $this->assertNotNull(AccountProfile::onlyTrashed()->find((string) $profile->_id));

        $executed = $this->runRepairCommand(execute: true);

        $this->assertSame(1, $executed['totals']['deleted_test_seed']);
        $this->assertNotNull(Account::onlyTrashed()->find((string) $account->_id));
        $this->assertSame(0, $this->runRepairCommand()['totals']['invalid']);
    }

    public function test_repair_skips_when_profile_type_is_missing(): void
    {
        $this->createCorruptedAccount('merchant-missing-type', 'merchant-missing-type', 'deleted-type');

        $dryRun = $this->runRepairCommand();

        $this->assertSame(1, $dryRun['totals']['skipped']);
        $this->assertSame(1, $dryRun['totals']['residual']);
        $this->assertSame('missing_profile_type', $dryRun['rows'][0]['policy_branch']);
        $this->assertSame('missing_profile_type', $dryRun['rows'][0]['residual_reason']);
    }

    public function test_repair_skips_when_linked_data_is_present(): void
    {
        [, $profile] = $this->createCorruptedAccount('merchant-linked-data', 'merchant-linked-data');
        InviteEdge::create([
            'receiver_account_profile_id' => (string) $profile->_id,
            'status' => 'pending',
            'source' => 'test',
        ]);

        $dryRun = $this->runRepairCommand();

        $this->assertSame(1, $dryRun['totals']['skipped']);
        $this->assertSame('linked_data_present', $dryRun['rows'][0]['policy_branch']);
        $this->assertGreaterThan(0, $dryRun['rows'][0]['linked_data']['invite_edges']);
    }

    public function test_repair_skips_when_no_restorable_profile_exists_for_non_test_account(): void
    {
        Account::create([
            'name' => 'No Restorable Account',
            'slug' => 'merchant-no-restorable',
            'document' => 'DOC-NO-RESTORABLE-'.uniqid(),
            'ownership_state' => 'tenant_owned',
        ]);

        $dryRun = $this->runRepairCommand();

        $this->assertSame(1, $dryRun['totals']['skipped']);
        $this->assertSame('no_restorable_profile', $dryRun['rows'][0]['policy_branch']);
    }

    public function test_execute_requires_explicit_tenant_confirmation(): void
    {
        $exitCode = Artisan::call('accounts:missing-profiles:repair', [
            'tenant_slug' => 'tenant-zeta',
            '--execute' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Execute mode requires --confirm=repair-missing-profiles:tenant-zeta.',
            Artisan::output()
        );
    }

    /**
     * @return array{Account, AccountProfile}
     */
    private function createCorruptedAccount(
        string $name,
        string $slug,
        string $profileType = 'personal',
    ): array {
        $account = Account::create([
            'name' => $name,
            'slug' => $slug,
            'document' => 'DOC-'.strtoupper(str_replace('-', '_', $slug)).'-'.uniqid(),
            'ownership_state' => 'tenant_owned',
        ])->fresh();

        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => $profileType,
            'display_name' => $name,
            'is_active' => true,
        ])->fresh();
        $profile->delete();

        return [$account, $profile];
    }

    /**
     * @return array<string, mixed>
     */
    private function runRepairCommand(bool $execute = false): array
    {
        $arguments = [
            'tenant_slug' => 'tenant-zeta',
            '--chunk' => 10,
        ];
        if ($execute) {
            $arguments['--execute'] = true;
            $arguments['--confirm'] = 'repair-missing-profiles:tenant-zeta';
        }

        $exitCode = Artisan::call('accounts:missing-profiles:repair', $arguments);
        $output = Artisan::output();
        Tenant::query()->where('slug', 'tenant-zeta')->firstOrFail()->makeCurrent();
        $this->assertSame(0, $exitCode, $output);
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        $this->assertIsInt($start, $output);
        $this->assertIsInt($end, $output);

        return json_decode(substr($output, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
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
