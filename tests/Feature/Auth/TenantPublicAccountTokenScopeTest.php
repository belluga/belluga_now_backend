<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Accounts\AccountUserService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Laravel\Sanctum\NewAccessToken;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantPublicAccountTokenScopeTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $accountRoleTemplate;

    private AccountUser $accountUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();

        [$this->account, $this->accountRoleTemplate] = $this->seedAccountWithRole([
            'account-users:view',
        ]);

        $accountUserService = $this->app->make(AccountUserService::class);
        $this->accountUser = $accountUserService->create(
            $this->account,
            [
                'name' => 'Scoped User',
                'email' => uniqid('scoped-user-', true).'@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->accountRoleTemplate->_id
        );

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Scoped Profile',
            'is_active' => true,
            'visibility' => 'public',
        ]);
    }

    public function test_agenda_accepts_current_tenant_account_token(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(200);
    }

    public function test_account_profiles_accepts_current_tenant_account_token(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
    }

    public function test_agenda_rejects_account_token_with_foreign_tenant_scope(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);
        $newToken->accessToken->setAttribute('tenant_id', 'foreign-tenant-id');
        $newToken->accessToken->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(403);
    }

    public function test_account_profiles_rejects_account_token_with_foreign_tenant_scope(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);
        $newToken->accessToken->setAttribute('tenant_id', 'foreign-tenant-id');
        $newToken->accessToken->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(403);
    }

    public function test_account_profiles_first_page_accepts_anonymous_tenant_token(): void
    {
        $token = $this->issueAnonymousIdentityToken();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('venue', $data[0]['profile_type'] ?? null);
    }

    public function test_agenda_accepts_anonymous_tenant_token(): void
    {
        $token = $this->issueAnonymousIdentityToken();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(200);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Scoped', 'subdomain' => 'tenant-scoped'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-scoped.test']
        );

        $service->initialize($payload);
    }

    private function issueScopedToken(AccountUser $user): NewAccessToken
    {
        $tokenService = $this->app->make(TenantScopedAccessTokenService::class);

        return $tokenService->issueForAccountUser(
            $user,
            'scoped-test-token',
            ['account-users:view']
        );
    }

    private function issueAnonymousIdentityToken(): string
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => 'tenant-public-discovery-test-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'tenant-public-discovery-test-device'),
                'user_agent' => 'TenantPublicAccountTokenScopeTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => [
                'source' => 'feature-test',
            ],
        ]);

        $response->assertStatus(201);

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', trim($token));

        return $token;
    }
}
