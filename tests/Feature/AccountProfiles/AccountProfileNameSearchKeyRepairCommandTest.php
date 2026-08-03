<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileNameSearchKeyRepairCommandTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrFail()->makeCurrent();

        AccountProfile::query()->delete();
        Account::query()->delete();
    }

    public function test_repair_command_backfills_missing_name_search_keys_for_a_tenant(): void
    {
        $frecha = $this->createProfileWithMissingNameSearchKey('Frecha', 'frecha');
        $paulo = $this->createProfileWithMissingNameSearchKey('Paulo César', 'paulo-cesar');
        $this->setWhitespaceOnlyNameSearchKey($paulo);

        $dryRunExitCode = Artisan::call('account-profiles:name-search-keys:repair', [
            'tenant_slug' => $this->tenant->slug,
        ]);
        $dryRunOutput = Artisan::output();

        $this->assertSame(0, $dryRunExitCode, $dryRunOutput);
        $this->assertStringContainsString('"dry_run": true', $dryRunOutput);
        $this->assertStringContainsString('"missing_name_search_keys": 2', $dryRunOutput);
        Tenant::query()->firstOrFail()->makeCurrent();
        $this->assertNull(AccountProfile::query()->findOrFail($frecha->_id)->getAttribute('name_search_key'));
        $this->assertNull(AccountProfile::query()->findOrFail($paulo->_id)->getAttribute('name_search_key'));

        $executeExitCode = Artisan::call('account-profiles:name-search-keys:repair', [
            'tenant_slug' => $this->tenant->slug,
            '--execute' => true,
            '--confirm' => 'repair-account-profile-name-search-keys:'.$this->tenant->slug,
        ]);
        $executeOutput = Artisan::output();

        $this->assertSame(0, $executeExitCode, $executeOutput);
        $this->assertStringContainsString('"dry_run": false', $executeOutput);
        $this->assertStringContainsString('"updated_profiles": 2', $executeOutput);
        Tenant::query()->firstOrFail()->makeCurrent();
        $this->assertSame('frecha', AccountProfile::query()->findOrFail($frecha->_id)->getAttribute('name_search_key'));
        $this->assertSame('paulo cesar', AccountProfile::query()->findOrFail($paulo->_id)->getAttribute('name_search_key'));
    }

    public function test_repair_command_repairs_all_tenants_and_restores_runtime_state(): void
    {
        $primaryTenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $primaryProfile = $this->createProfileWithMissingNameSearchKey('Frecha Global', 'frecha-global');

        $secondaryTenant = $this->createPassiveTenant('Tenant Omega', 'tenant-omega');

        $secondaryTenant->makeCurrent();
        $secondaryProfile = $this->createProfileWithMissingNameSearchKey('Paulo Global', 'paulo-global');
        $secondaryTenant->forgetCurrent();
        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $dryRunExitCode = Artisan::call('account-profiles:name-search-keys:repair', [
            '--all' => true,
        ]);
        $dryRunOutput = Artisan::output();

        $this->assertSame(0, $dryRunExitCode, $dryRunOutput);
        $this->assertStringContainsString('"tenant_count": 2', $dryRunOutput);
        $this->assertStringContainsString('"dry_run": true', $dryRunOutput);
        $this->assertStringContainsString('"missing_name_search_keys": 2', $dryRunOutput);
        $this->assertTenantRuntimeRestoredAfterCommand($primaryTenant, 'landlord');

        $this->assertNull(AccountProfile::query()->findOrFail($primaryProfile->_id)->getAttribute('name_search_key'));
        $secondaryTenant->makeCurrent();
        $this->assertNull(AccountProfile::query()->findOrFail($secondaryProfile->_id)->getAttribute('name_search_key'));
        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $executeExitCode = Artisan::call('account-profiles:name-search-keys:repair', [
            '--all' => true,
            '--execute' => true,
            '--confirm' => 'repair-account-profile-name-search-keys:all',
        ]);
        $executeOutput = Artisan::output();

        $this->assertSame(0, $executeExitCode, $executeOutput);
        $this->assertStringContainsString('"tenant_count": 2', $executeOutput);
        $this->assertStringContainsString('"dry_run": false', $executeOutput);
        $this->assertStringContainsString('"updated_profiles": 2', $executeOutput);
        $this->assertTenantRuntimeRestoredAfterCommand($primaryTenant, 'landlord');

        $this->assertSame('frecha global', AccountProfile::query()->findOrFail($primaryProfile->_id)->getAttribute('name_search_key'));
        $secondaryTenant->makeCurrent();
        $this->assertSame('paulo global', AccountProfile::query()->findOrFail($secondaryProfile->_id)->getAttribute('name_search_key'));
    }

    public function test_repair_command_restores_runtime_state_after_exception_during_all_tenants_flow(): void
    {
        $primaryTenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();

        $this->createPassiveTenant('Tenant Omega', 'tenant-omega');

        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        /** @var array<string, mixed> $originalTenantConnection */
        $originalTenantConnection = config('database.connections.tenant');

        try {
            config([
                'database.connections.tenant.driver' => 'unsupported-driver-for-test',
            ]);

            try {
                Artisan::call('account-profiles:name-search-keys:repair', [
                    '--all' => true,
                ]);
                $this->fail('Expected the repair command to throw when the tenant connection driver is invalid.');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString('unsupported-driver-for-test', $exception->getMessage());
            }

            $this->assertTenantRuntimeRestoredAfterCommand($primaryTenant, 'landlord');
        } finally {
            config([
                'database.connections.tenant' => $originalTenantConnection,
            ]);
            DB::purge('tenant');
            $primaryTenant->makeCurrent();
            DB::setDefaultConnection('landlord');
        }
    }

    private function createProfileWithMissingNameSearchKey(string $displayName, string $slug): AccountProfile
    {
        $account = Account::create([
            'name' => "Account {$displayName}",
            'slug' => "account-{$slug}",
            'document' => 'DOC-'.strtoupper(str_replace('-', '-', $slug)).'-'.uniqid(),
        ])->fresh();

        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'venue',
            'display_name' => $displayName,
            'slug' => $slug,
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profiles')
            ->updateOne(
                ['_id' => $profile->_id],
                ['$unset' => ['name_search_key' => true]],
            );

        return $profile;
    }

    private function createPassiveTenant(string $name, string $subdomain): Tenant
    {
        return Tenant::withoutEvents(function () use ($name, $subdomain): Tenant {
            $slug = $subdomain;

            return Tenant::query()->create([
                'name' => $name,
                'slug' => $slug,
                'subdomain' => $subdomain,
                'database' => Tenant::tenantDatabasePrefix().str_replace('-', '_', $slug),
                'app_domains' => ["{$subdomain}.test"],
            ])->fresh();
        });
    }

    private function assertTenantRuntimeRestoredAfterCommand(Tenant $tenant, string $expectedDefaultConnection): void
    {
        $this->assertSame((string) $tenant->getKey(), (string) Tenant::current()?->getKey());
        $this->assertSame($expectedDefaultConnection, DB::getDefaultConnection());
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

    protected function prepareAuthenticatedHarnessState(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();
    }

    private function setWhitespaceOnlyNameSearchKey(AccountProfile $profile): void
    {
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profiles')
            ->updateOne(
                ['_id' => $profile->_id],
                ['$set' => ['name_search_key' => '   ']],
            );
    }
}
