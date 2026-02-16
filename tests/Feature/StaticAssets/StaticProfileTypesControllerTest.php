<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticProfileType;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class StaticProfileTypesControllerTest extends TestCaseTenant
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

    public function testStaticProfileTypeIndexListsRegistry(): void
    {
        StaticProfileType::query()->delete();
        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'beach',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_poi_enabled' => true,
                'has_bio' => true,
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.type', 'poi');
        $response->assertJsonPath('data.0.map_category', 'beach');
    }

    public function testStaticProfileTypeCreate(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            [
                'type' => 'beach',
                'label' => 'Beach',
                'map_category' => 'beach',
                'allowed_taxonomies' => ['vibe'],
                'capabilities' => [
                    'is_poi_enabled' => true,
                    'has_content' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'beach');
        $response->assertJsonPath('data.map_category', 'beach');
        $response->assertJsonPath('data.capabilities.has_content', true);
    }

    public function testStaticProfileTypeCreateValidation(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testStaticProfileTypeCreateRejectsDuplicateType(): void
    {
        StaticProfileType::query()->delete();
        StaticProfileType::create([
            'type' => 'beach',
            'label' => 'Beach',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            [
                'type' => 'beach',
                'label' => 'Beach',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testStaticProfileTypeUpdate(): void
    {
        StaticProfileType::query()->delete();
        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'poi',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}static_profile_types/poi",
            [
                'label' => 'POI Atualizado',
                'map_category' => 'historic',
                'capabilities' => [
                    'has_bio' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.label', 'POI Atualizado');
        $response->assertJsonPath('data.map_category', 'historic');
        $response->assertJsonPath('data.capabilities.has_bio', true);
    }

    public function testStaticProfileTypeDelete(): void
    {
        StaticProfileType::query()->delete();
        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'poi',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $this->deleteJson(
            "{$this->base_tenant_api_admin}static_profile_types/poi",
            [],
            $this->getHeaders()
        )->assertStatus(200);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}static_profile_types",
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
