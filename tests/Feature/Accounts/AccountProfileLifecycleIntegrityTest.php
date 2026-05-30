<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Symfony\Component\Process\Process;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileLifecycleIntegrityTest extends TestCaseTenant
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

    public function test_direct_profile_delete_rejects_last_active_profile_for_live_account(): void
    {
        [$account, $profile] = $this->createLiveAccountWithProfile('Last Active Delete');

        $response = $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['account_profile_lifecycle']);
        $this->assertNull(AccountProfile::query()->find((string) $profile->_id)?->deleted_at);
        $this->assertSame(
            1,
            AccountProfile::query()
                ->where('account_id', (string) $account->_id)
                ->where('is_active', true)
                ->count()
        );
    }

    public function test_direct_profile_force_delete_rejects_last_active_profile_for_live_account_with_response_and_state_assertions(): void
    {
        [$account, $profile] = $this->createLiveAccountWithProfile('Last Active Force Delete');

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id.'/force_delete',
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['account_profile_lifecycle']);
        $this->assertNotNull(AccountProfile::query()->find((string) $profile->_id));
        $this->assertSame(
            1,
            AccountProfile::query()
                ->where('account_id', (string) $account->_id)
                ->where('is_active', true)
                ->count()
        );
    }

    public function test_direct_profile_force_delete_rejects_last_soft_deleted_restorable_profile_for_live_account_with_response_and_state_assertions(): void
    {
        [$account, $profile] = $this->createLiveAccountWithProfile('Last Restorable Force Delete');
        $profile->delete();

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id.'/force_delete',
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['account_profile_lifecycle']);
        $this->assertNotNull(AccountProfile::onlyTrashed()->find((string) $profile->_id));
        $this->assertSame(
            0,
            AccountProfile::query()
                ->where('account_id', (string) $account->_id)
                ->where('is_active', true)
                ->count()
        );
        $this->assertSame(
            1,
            AccountProfile::onlyTrashed()
                ->where('account_id', (string) $account->_id)
                ->count()
        );
    }

    public function test_concurrent_direct_profile_deletes_cannot_orphan_live_account(): void
    {
        [$account, $profile] = $this->createLiveAccountWithProfile('Concurrent Delete');
        $profileId = (string) $profile->_id;

        $first = $this->profileDeleteProcess($profileId);
        $second = $this->profileDeleteProcess($profileId);

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        Tenant::query()->where('slug', 'tenant-zeta')->firstOrFail()->makeCurrent();

        $this->assertNotNull(AccountProfile::query()->find($profileId));
        $this->assertSame(
            1,
            AccountProfile::query()
                ->where('account_id', (string) $account->_id)
                ->where('is_active', true)
                ->count()
        );
    }

    /**
     * @return array{Account, AccountProfile}
     */
    private function createLiveAccountWithProfile(string $name): array
    {
        $account = Account::create([
            'name' => $name.' Account',
            'document' => 'DOC-'.strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
            'ownership_state' => 'tenant_owned',
        ])->fresh();

        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => $name,
            'is_active' => true,
        ])->fresh();

        return [$account, $profile];
    }

    private function profileDeleteProcess(string $profileId): Process
    {
        $code = str_replace('__PROFILE_ID__', addslashes($profileId), <<<'PHP'
$tenant = \App\Models\Landlord\Tenant::query()->where('slug', 'tenant-zeta')->firstOrFail();
$tenant->makeCurrent();
$profile = \App\Models\Tenants\AccountProfile::query()->findOrFail('__PROFILE_ID__');
app(\App\Application\AccountProfiles\AccountProfileManagementService::class)->delete($profile);
PHP);

        return new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), null, null, 30);
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
