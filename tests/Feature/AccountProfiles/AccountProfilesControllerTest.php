<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;
use Tests\Helpers\TenantLabels;

class AccountProfilesControllerTest extends TestCaseTenant
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

        [$this->account, $this->accountRoleTemplate] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_poi_enabled' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);
    }

    public function testAccountProfileIndexAccessibleForAccountUser(): void
    {
        $user = $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile Viewer',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    public function testPublicAccountProfileIndexFiltersByProfileType(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile Personal',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Account Secondary',
            'document' => 'DOC-SECONDARY',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Profile Venue',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?filter[profile_type]=venue"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertNotEmpty($items);
        $this->assertTrue($items->every(fn (array $item): bool => $item['profile_type'] === 'venue'));
    }

    public function testPublicAccountProfileIndexReturnsEmptyWhenNone(): void
    {
        $this->createAccountUser([]);

        AccountProfile::query()->delete();

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function testAccountProfileTypesReturnsRegistry(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}account_profile_types", $this->getHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertNotEmpty($response->json('data'));
    }

    public function testAccountProfileTypesForbiddenWithoutAbility(): void
    {
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, ['account-users:create']);

        $response = $this->getJson("{$this->base_tenant_api_admin}account_profile_types");

        $response->assertStatus(403);
    }

    public function testAccountProfileCreateRequiresLocationWhenPoiEnabled(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'venue',
                'display_name' => 'Test Venue',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);

        $created = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'venue',
                'display_name' => 'Test Venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $created->assertJsonPath('data.account_id', (string) $this->account->_id);
        $created->assertJsonPath('data.profile_type', 'venue');
    }

    public function testAccountProfileCreateStoresAvatarAndCoverUploads(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'personal',
                'display_name' => 'Profile Media',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $response->assertStatus(201);
        $avatarUrl = $response->json('data.avatar_url');
        $coverUrl = $response->json('data.cover_url');
        $this->assertNotEmpty($avatarUrl);
        $this->assertNotEmpty($coverUrl);

        $profileId = (string) $response->json('data.id');
        $this->assertMediaUrlHealthy($avatarUrl);
        $this->assertMediaUrlHealthy($coverUrl);
        $this->assertMediaStored($profileId, 'avatar');
        $this->assertMediaStored($profileId, 'cover');
    }

    public function testAccountProfileUpdateReplacesAvatarUpload(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'personal',
                'display_name' => 'Profile Replace',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.id');
        $originalAvatarUrl = $createResponse->json('data.avatar_url');
        $this->assertNotEmpty($originalAvatarUrl);
        $originalPath = $this->assertMediaStored($profileId, 'avatar');

        $updateResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                '_method' => 'PATCH',
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 220, 220),
            ],
        );

        $updateResponse->assertStatus(200);
        $newAvatarUrl = $updateResponse->json('data.avatar_url');
        $this->assertNotEmpty($newAvatarUrl);

        $this->assertMediaUrlHealthy($newAvatarUrl);
        $this->assertMediaStored($profileId, 'avatar');
        if ($originalPath) {
            Storage::disk('public')->assertMissing($originalPath);
        }
    }

    public function testAccountProfileRemoveAvatarAndCoverClearsMedia(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'personal',
                'display_name' => 'Profile Remove',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.id');
        $avatarPath = $this->assertMediaStored($profileId, 'avatar');
        $coverPath = $this->assertMediaStored($profileId, 'cover');

        $removeResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'remove_avatar' => true,
                'remove_cover' => true,
            ],
            $this->getHeaders()
        );

        $removeResponse->assertStatus(200);
        $this->assertNull($removeResponse->json('data.avatar_url'));
        $this->assertNull($removeResponse->json('data.cover_url'));
        Storage::disk('public')->assertMissing($avatarPath);
        Storage::disk('public')->assertMissing($coverPath);
    }

    public function testAccountProfileCreateRejectsUnknownProfileType(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'unknown_type',
                'display_name' => 'Unknown Profile',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
    }

    public function testAccountProfileCreateRejectsMissingAccount(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => '605b9b3b8f1d2c6d88f4c123',
                'profile_type' => 'personal',
                'display_name' => 'Missing Account',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.account_id'));
    }

    public function testAccountProfileCreateForbiddenWithoutAbility(): void
    {
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, ['account-users:view']);

        $response = $this->postJson("{$this->base_tenant_api_admin}account_profiles", [
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Personal',
        ]);

        $response->assertStatus(403);
    }

    public function testAccountProfileUpdateRejectsInvalidProfileType(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'profile_type' => 'invalid_type',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
    }

    public function testAccountProfileIndexFiltersByAccount(): void
    {
        $otherAccount = Account::create([
            'name' => 'Account B',
            'document' => 'DOC-B',
        ]);

        $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'personal',
                'display_name' => 'Profile A',
            ],
            $this->getHeaders()
        )->assertStatus(201);

        $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $otherAccount->_id,
                'profile_type' => 'personal',
                'display_name' => 'Profile B',
            ],
            $this->getHeaders()
        )->assertStatus(201);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles?account_id=" . (string) $this->account->_id,
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue($items->every(fn (array $item): bool => $item['account_id'] === (string) $this->account->_id));
    }

    public function testAccountProfileGeoIndexRequiresAuth(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}account_profiles/geo");

        $response->assertStatus(401);
    }

    public function testAccountProfileGeoIndexFiltersByType(): void
    {
        $venue = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'venue',
                'display_name' => 'Venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders()
        );

        $venue->assertStatus(201);

        $secondaryAccount = Account::create([
            'name' => 'Account Secondary',
            'document' => 'DOC-SECONDARY-' . uniqid(),
        ]);

        $personal = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $secondaryAccount->_id,
                'profile_type' => 'personal',
                'display_name' => 'Personal',
                'location' => [
                    'lat' => -20.1,
                    'lng' => -40.1,
                ],
            ],
            $this->getHeaders()
        );

        $personal->assertStatus(201);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/geo?origin_lat=-20.0&origin_lng=-40.0&profile_type=venue",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertTrue(collect($data)->every(fn (array $item): bool => $item['profile_type'] === 'venue'));
    }

    public function testAccountProfileGeoIndexSkipsInactiveProfiles(): void
    {
        $created = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => (string) $this->account->_id,
                'profile_type' => 'venue',
                'display_name' => 'Inactive Venue',
                'location' => [
                    'lat' => -21.0,
                    'lng' => -41.0,
                ],
            ],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $profileId = $created->json('data.id');

        $profile = AccountProfile::query()->where('_id', $profileId)->firstOrFail();
        $profile->is_active = false;
        $profile->save();

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/geo?origin_lat=-21.0&origin_lng=-41.0",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($profileId));
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

    private function createAccountUser(array $permissions): AccountUser
    {
        $service = $this->app->make(AccountUserService::class);
        $user = $service->create(
            $this->account,
            [
                'name' => 'Account Viewer',
                'email' => uniqid('account-viewer', true) . '@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->accountRoleTemplate->_id
        );

        Sanctum::actingAs($user, $permissions);

        return $user;
    }

    private function assertMediaUrlHealthy(?string $url): void
    {
        $this->assertNotEmpty($url);
        $this->assertStringContainsString(
            "{$this->base_tenant_url}account-profiles/",
            $url
        );
        $this->assertStringContainsString('v=', $url);

        $response = $this->get($url);
        $response->assertStatus(200);
    }

    private function assertMediaStored(string $profileId, string $kind): string
    {
        $tenant = Tenant::current();
        $tenantSlug = $tenant?->slug ?? $this->tenant->subdomain;
        $directory = "tenants/{$tenantSlug}/account_profiles/{$profileId}";
        $files = Storage::disk('public')->files($directory);
        $match = collect($files)->first(
            fn (string $path): bool => str_contains(basename($path), "{$kind}.")
        );
        $this->assertNotEmpty($match);

        return $match;
    }

    private function getMultipartHeaders(): array
    {
        $headers = $this->getHeaders();
        unset($headers['Content-Type']);
        $headers['Accept'] = 'application/json';

        return $headers;
    }
}
