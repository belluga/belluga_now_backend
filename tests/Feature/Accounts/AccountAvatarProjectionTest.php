<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountAvatarProjectionTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private string $tenantAccountsAdminUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        $this->account->makeCurrent();

        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), [
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenantHost = "{$tenant->subdomain}.{$this->host}";
        $this->tenantAccountsAdminUrl = "http://{$tenantHost}/admin/api/v1/accounts";
    }

    public function test_index_and_show_include_avatar_url_projected_from_account_profile(): void
    {
        $avatarUrl = 'https://cdn.example.com/account-avatar.png';

        $profile = AccountProfile::query()
            ->where('account_id', (string) $this->account->_id)
            ->first();
        if (! $profile) {
            $profile = AccountProfile::create([
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'personal',
                'display_name' => 'Account Avatar Profile',
            ]);
        }
        $profile->avatar_url = $avatarUrl;
        $profile->save();

        $indexResponse = $this->getJson($this->tenantAccountsAdminUrl);

        $indexResponse->assertOk();
        $indexed = collect($indexResponse->json('data'))
            ->firstWhere('id', (string) $this->account->_id);
        $this->assertIsArray($indexed);
        $this->assertSame($avatarUrl, $indexed['avatar_url'] ?? null);

        $showResponse = $this->getJson("{$this->tenantAccountsAdminUrl}/{$this->account->slug}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.avatar_url', $avatarUrl);
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

