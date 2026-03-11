<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
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

    public function test_static_profile_type_index_lists_registry(): void
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

    public function test_static_profile_type_create(): void
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

    public function test_static_profile_type_create_validation(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_static_profile_type_create_rejects_duplicate_type(): void
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

    public function test_static_profile_type_update(): void
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
        StaticProfileType::create([
            'type' => 'kiosk',
            'label' => 'Kiosk',
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

    public function test_static_profile_type_update_allows_type_rename_and_propagates_dependents(): void
    {
        StaticProfileType::query()->delete();
        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'poi',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $asset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Asset One',
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => (string) $asset->_id,
            'name' => 'Asset One',
            'category' => 'poi',
            'is_active' => true,
        ]);
        $otherAsset = StaticAsset::create([
            'profile_type' => 'kiosk',
            'display_name' => 'Asset Two',
            'is_active' => true,
        ]);
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => (string) $otherAsset->_id,
            'name' => 'Asset Two',
            'category' => 'poi',
            'is_active' => true,
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}static_profile_types/poi",
            [
                'type' => 'landmark',
                'label' => 'Landmark',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'landmark');
        $response->assertJsonPath('data.map_category', 'landmark');

        $this->assertTrue(StaticProfileType::query()->where('type', 'landmark')->exists());
        $this->assertFalse(StaticProfileType::query()->where('type', 'poi')->exists());
        $this->assertSame(
            'landmark',
            (string) (StaticAsset::query()->findOrFail($asset->_id)->profile_type ?? '')
        );
        $this->assertSame(
            'landmark',
            (string) (
                MapPoi::query()
                    ->where('ref_type', 'static')
                    ->where('ref_id', (string) $asset->_id)
                    ->firstOrFail()
                    ->category ?? ''
            )
        );
        $this->assertSame(
            'poi',
            (string) (
                MapPoi::query()
                    ->where('ref_type', 'static')
                    ->where('ref_id', (string) $otherAsset->_id)
                    ->firstOrFail()
                    ->category ?? ''
            )
        );
    }

    public function test_static_profile_type_update_propagates_map_category_without_type_rename(): void
    {
        StaticProfileType::query()->delete();
        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'poi',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $asset = StaticAsset::create([
            'profile_type' => 'poi',
            'display_name' => 'Asset One',
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => (string) $asset->_id,
            'name' => 'Asset One',
            'category' => 'poi',
            'is_active' => true,
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}static_profile_types/poi",
            [
                'map_category' => 'landmark',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'poi');
        $response->assertJsonPath('data.map_category', 'landmark');

        $this->assertSame(
            'landmark',
            (string) (
                MapPoi::query()
                    ->where('ref_type', 'static')
                    ->where('ref_id', (string) $asset->_id)
                    ->firstOrFail()
                    ->category ?? ''
            )
        );
    }

    public function test_static_profile_type_update_rejects_duplicate_type_rename(): void
    {
        StaticProfileType::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'poi',
            'allowed_taxonomies' => [],
            'capabilities' => [],
        ]);
        StaticProfileType::create([
            'type' => 'landmark',
            'label' => 'Landmark',
            'map_category' => 'landmark',
            'allowed_taxonomies' => [],
            'capabilities' => [],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}static_profile_types/poi",
            [
                'type' => 'landmark',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    }

    public function test_static_profile_type_delete(): void
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
