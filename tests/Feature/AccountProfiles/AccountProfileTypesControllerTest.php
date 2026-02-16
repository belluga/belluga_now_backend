<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\TenantProfileType;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfileTypesControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

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

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
    }

    public function testProfileTypeIndexListsRegistry(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => ['music_genre'],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.type', 'artist');
    }

    public function testProfileTypeCreate(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => ['cuisine'],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'venue');
        $response->assertJsonPath('data.capabilities.is_poi_enabled', true);
    }

    public function testProfileTypeCreateValidation(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testProfileTypeCreateRejectsDuplicateType(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testProfileTypeCreateValidatesAllowedTaxonomiesLength(): void
    {
        $longValue = str_repeat('a', 300);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => [$longValue],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testProfileTypeUpdate(): void
    {
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

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/personal",
            [
                'label' => 'Pessoa',
                'capabilities' => [
                    'is_favoritable' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.label', 'Pessoa');
        $response->assertJsonPath('data.capabilities.is_favoritable', true);
    }

    public function testProfileTypeUpdateUsesRouteParam(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'restaurante',
            'label' => 'Restaurante',
            'allowed_taxonomies' => ['cuisine', 'genre'],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/restaurante",
            [
                'label' => 'Restaurante Atualizado',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'restaurante');
        $response->assertJsonPath('data.label', 'Restaurante Atualizado');
    }

    public function testProfileTypeUpdateAllowsTypeRenameAndPropagatesDependents(): void
    {
        TenantProfileType::query()->delete();
        AccountProfile::query()->delete();
        MapPoi::query()->delete();

        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => 'account-123',
            'profile_type' => 'personal',
            'display_name' => 'Profile One',
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $profile->_id,
            'name' => 'Profile One',
            'category' => 'personal',
            'is_active' => true,
        ]);
        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => 'external-profile',
            'name' => 'External Profile',
            'category' => 'personal',
            'is_active' => true,
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/personal",
            [
                'type' => 'creator',
                'label' => 'Creator',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'creator');
        $response->assertJsonPath('data.label', 'Creator');

        $this->assertTrue(TenantProfileType::query()->where('type', 'creator')->exists());
        $this->assertFalse(TenantProfileType::query()->where('type', 'personal')->exists());
        $this->assertSame(
            'creator',
            (string) (AccountProfile::query()->findOrFail($profile->_id)->profile_type ?? '')
        );
        $this->assertSame(
            'creator',
            (string) (
                MapPoi::query()
                    ->where('ref_type', 'account_profile')
                    ->where('ref_id', (string) $profile->_id)
                    ->firstOrFail()
                    ->category ?? ''
            )
        );
        $this->assertSame(
            'personal',
            (string) (
                MapPoi::query()
                    ->where('ref_type', 'account_profile')
                    ->where('ref_id', 'external-profile')
                    ->firstOrFail()
                    ->category ?? ''
            )
        );
    }

    public function testProfileTypeUpdateRejectsDuplicateTypeRename(): void
    {
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [],
        ]);
        TenantProfileType::create([
            'type' => 'creator',
            'label' => 'Creator',
            'allowed_taxonomies' => [],
            'capabilities' => [],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/personal",
            [
                'type' => 'creator',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function testProfileTypeDelete(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profile_types/artist",
            [],
            $this->getHeaders()
        )->assertStatus(200);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
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
