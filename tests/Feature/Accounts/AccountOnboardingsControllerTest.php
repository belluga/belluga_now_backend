<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountOnboardingsControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private string $tenantOnboardingsUrl;
    private string $tenantAccountsLegacyUrl;
    private string $tenantAccountProfilesLegacyUrl;
    private string $tenantLoginUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenant->makeCurrent();

        TenantProfileType::query()->delete();
        TenantProfileType::query()->create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_poi_enabled' => false,
            ],
        ]);
        TenantProfileType::query()->create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $tenantHost = "{$tenant->subdomain}.{$this->host}";
        $this->tenantOnboardingsUrl = "http://{$tenantHost}/admin/api/v1/account_onboardings";
        $this->tenantAccountsLegacyUrl = "http://{$tenantHost}/admin/api/v1/accounts";
        $this->tenantAccountProfilesLegacyUrl = "http://{$tenantHost}/admin/api/v1/account_profiles";
        $this->tenantLoginUrl = "http://{$tenantHost}/admin/api/v1/auth/login";
    }

    public function testOnboardingSuccessCreatesAccountRoleAndProfile(): void
    {
        $this->actingAsAdmin(['account-users:create']);
        $name = 'Onboarding Success ' . Str::random(8);

        $response = $this->postJson($this->tenantOnboardingsUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account_profile.display_name', $name);
        $response->assertJsonPath('data.account_profile.profile_type', 'personal');

        $accountId = (string) $response->json('data.account.id');
        $roleId = (string) $response->json('data.role.id');
        $profileId = (string) $response->json('data.account_profile.id');

        $this->assertNotNull(Account::query()->where('_id', $accountId)->first());
        $this->assertSame(1, AccountProfile::query()->where('_id', $profileId)->count());
        $this->assertSame(1, \App\Models\Tenants\AccountRoleTemplate::query()->where('_id', $roleId)->count());
        $this->assertSame($accountId, (string) $response->json('data.account_profile.account_id'));
    }

    public function testOnboardingProfileValidationFailureRollsBackAccountCreation(): void
    {
        $this->actingAsAdmin(['account-users:create']);
        $name = 'Onboarding Rollback ' . Str::random(8);

        $response = $this->postJson($this->tenantOnboardingsUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'unsupported_type',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
        $this->assertSame(0, Account::query()->where('name', $name)->count());
        $this->assertSame(0, AccountProfile::query()->where('display_name', $name)->count());
    }

    public function testOnboardingInvalidAccountPayloadDoesNotCreateProfile(): void
    {
        $this->actingAsAdmin(['account-users:create']);
        $profilesBefore = AccountProfile::query()->count();
        $accountsBefore = Account::query()->count();

        $response = $this->postJson($this->tenantOnboardingsUrl, [
            'name' => '',
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.name'));
        $this->assertSame($accountsBefore, Account::query()->count());
        $this->assertSame($profilesBefore, AccountProfile::query()->count());
    }

    public function testOnboardingLocationValidationUsesFieldAligned422Keys(): void
    {
        $this->actingAsAdmin(['account-users:create']);

        $response = $this->postJson($this->tenantOnboardingsUrl, [
            'name' => 'Missing Location ' . Str::random(6),
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'venue',
        ]);

        $response->assertStatus(422);
        $errors = (array) $response->json('errors');
        $this->assertNotEmpty($errors['location'] ?? null);
        $this->assertNotEmpty($errors['location.lat'] ?? null);
        $this->assertNotEmpty($errors['location.lng'] ?? null);
    }

    public function testOnboardingMediaFailureRollsBackAllDocuments(): void
    {
        $this->actingAsAdmin(['account-users:create']);
        $name = 'Onboarding Media Fail ' . Str::random(8);

        $mediaMock = Mockery::mock(AccountProfileMediaService::class);
        $mediaMock->shouldReceive('applyUploads')
            ->once()
            ->andThrow(new \RuntimeException('Simulated media write failure'));
        $this->app->instance(AccountProfileMediaService::class, $mediaMock);

        $response = $this->postJson($this->tenantOnboardingsUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.account'));
        $this->assertSame(0, Account::query()->where('name', $name)->count());
        $this->assertSame(0, AccountProfile::query()->where('display_name', $name)->count());
    }

    public function testLegacyRoutesReturnDeterministic409Rejection(): void
    {
        $this->actingAsAdmin(['account-users:create']);

        $accountResponse = $this->postJson($this->tenantAccountsLegacyUrl, [
            'name' => 'Legacy Account',
            'ownership_state' => 'tenant_owned',
        ]);
        $accountResponse->assertStatus(409);
        $accountResponse->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
        $accountResponse->assertJsonPath('meta.use_endpoint', '/admin/api/v1/account_onboardings');

        $profileResponse = $this->postJson($this->tenantAccountProfilesLegacyUrl, [
            'account_id' => '507f1f77bcf86cd799439011',
            'profile_type' => 'personal',
            'display_name' => 'Legacy Profile',
        ]);
        $profileResponse->assertStatus(409);
        $profileResponse->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
        $profileResponse->assertJsonPath('meta.use_endpoint', '/admin/api/v1/account_onboardings');
    }

    public function testRealLoginPathThenOnboardingAndRouteIsolationAndTenantAccessGuard(): void
    {
        $login = $this->postJson($this->tenantLoginUrl, [
            'email' => 'root@example.org',
            'password' => 'Secret!234',
            'device_name' => 'feature-test-device',
        ]);
        $login->assertOk();
        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);

        $tenantResponse = $this->postJson(
            $this->tenantOnboardingsUrl,
            [
                'name' => 'Login Flow ' . Str::random(6),
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
            ],
            ['Authorization' => "Bearer {$token}"]
        );
        $tenantResponse->assertCreated();

        $landlordHostResponse = $this->postJson(
            "http://{$this->host}/admin/api/v1/account_onboardings",
            [
                'name' => 'Landlord Host Blocked',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
            ],
            ['Authorization' => "Bearer {$token}"]
        );
        $landlordHostResponse->assertStatus(404);

        $noAccessUser = LandlordUser::query()->create([
            'name' => 'No Access User',
            'emails' => [strtolower('no-access-' . Str::random(8) . '@example.org')],
            'password' => 'Secret!234',
        ]);
        Sanctum::actingAs($noAccessUser, ['account-users:create']);

        $forbidden = $this->postJson(
            $this->tenantOnboardingsUrl,
            [
                'name' => 'Forbidden by Tenant Access',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
            ]
        );
        $forbidden->assertStatus(403);
    }

    private function actingAsAdmin(array $abilities): void
    {
        Sanctum::actingAs(
            LandlordUser::query()->firstOrFail(),
            $abilities
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
