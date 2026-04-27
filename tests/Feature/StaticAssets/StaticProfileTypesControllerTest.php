<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MongoDB\BSON\ObjectId;
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
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'place',
                    'color' => '#00AAFF',
                    'icon_color' => '#101010',
                ],
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
        $response->assertJsonPath('data.poi_visual.mode', 'icon');
        $response->assertJsonPath('data.poi_visual.icon', 'place');
        $response->assertJsonPath('data.poi_visual.color', '#00AAFF');
        $response->assertJsonPath('data.poi_visual.icon_color', '#101010');
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

    public function test_static_profile_type_create_rejects_invalid_poi_visual_icon_without_color_and_icon_color(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            [
                'type' => 'beach',
                'label' => 'Beach',
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'place',
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'poi_visual.color',
            'poi_visual.icon_color',
        ]);
    }

    public function test_static_profile_type_create_accepts_canonical_visual_type_asset_upload(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}static_profile_types",
            [
                'type' => 'landmark',
                'label' => 'Landmark',
                'map_category' => 'historic',
                'allowed_taxonomies' => ['region'],
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                    'color' => '#00897B',
                ],
                'type_asset' => UploadedFile::fake()->image('landmark.png', 320, 320),
                'capabilities' => [
                    'is_poi_enabled' => true,
                    'has_content' => true,
                ],
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.visual.mode', 'image');
        $response->assertJsonPath('data.visual.image_source', 'type_asset');
        $response->assertJsonPath('data.visual.color', '#00897B');
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'type_asset');
        $response->assertJsonPath('data.poi_visual.color', '#00897B');
        $typeAssetUrl = $response->json('data.visual.image_url');
        $this->assertIsString($typeAssetUrl);
        $this->assertStringContainsString('/api/v1/media/static-profile-types/', $typeAssetUrl);
        $this->assertSame($typeAssetUrl, $response->json('data.poi_visual.image_url'));

        $model = StaticProfileType::query()->where('type', 'landmark')->firstOrFail();
        $this->assertSame('#00897B', data_get($model->visual, 'color'));
        $this->assertTypeAssetStored((string) $model->getKey(), 'static_profile_types');
    }

    public function test_static_profile_type_create_rejects_type_asset_visual_without_upload(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_profile_types",
            [
                'type' => 'landmark-missing',
                'label' => 'Landmark Missing',
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type_asset']);
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

    public function test_static_profile_type_map_poi_projection_impact_returns_projection_count(): void
    {
        StaticProfileType::query()->delete();
        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'beach',
            'label' => 'Beach',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $first = StaticAsset::create([
            'profile_type' => 'beach',
            'display_name' => 'Beach One',
            'is_active' => true,
        ]);
        $second = StaticAsset::create([
            'profile_type' => 'beach',
            'display_name' => 'Beach Two',
            'is_active' => true,
        ]);
        $other = StaticAsset::create([
            'profile_type' => 'historic',
            'display_name' => 'Historic',
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => (string) $first->_id,
            'name' => 'Beach One',
            'category' => 'beach',
            'is_active' => true,
        ]);
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => (string) $second->_id,
            'name' => 'Beach Two',
            'category' => 'beach',
            'is_active' => true,
        ]);
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => (string) $other->_id,
            'name' => 'Historic',
            'category' => 'historic',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}static_profile_types/beach/map_poi_projection_impact",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.profile_type', 'beach');
        $response->assertJsonPath('data.projection_count', 2);
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
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
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
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
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

    public function test_static_profile_type_update_disables_poi_and_hard_deletes_related_projections(): void
    {
        StaticProfileType::query()->delete();
        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'beach',
            'label' => 'Beach',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $asset = StaticAsset::create([
            'profile_type' => 'beach',
            'display_name' => 'Beach One',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => new ObjectId((string) $asset->_id),
            'source_checkpoint' => 1,
            'name' => 'Beach One',
            'category' => 'beach',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}static_profile_types/beach",
            [
                'capabilities' => [
                    'is_poi_enabled' => false,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.capabilities.is_poi_enabled', false);
        $assetId = (string) $asset->_id;
        $remaining = MapPoi::query()
            ->where('ref_type', 'static')
            ->where(function ($query) use ($assetId): void {
                $query->where('ref_id', $assetId)
                    ->orWhere('ref_id', new ObjectId($assetId));
            })
            ->count();

        $this->assertSame(0, $remaining);
    }

    public function test_static_profile_type_update_poi_visual_change_rematerializes_projection_visual(): void
    {
        StaticProfileType::query()->delete();
        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'beach',
            'label' => 'Beach',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#2266AA',
                'icon_color' => '#FFFFFF',
            ],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $asset = StaticAsset::create([
            'profile_type' => 'beach',
            'display_name' => 'Beach One',
            'cover_url' => 'https://cdn.example.com/static-cover.png',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => new ObjectId((string) $asset->_id),
            'source_checkpoint' => 1,
            'name' => 'Beach One',
            'category' => 'beach',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#2266AA',
                'icon_color' => '#FFFFFF',
                'source' => 'type_definition',
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}static_profile_types/beach",
            [
                'poi_visual' => [
                    'mode' => 'image',
                    'image_source' => 'cover',
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'cover');
        $assetId = (string) $asset->_id;
        $projectionQuery = MapPoi::query()
            ->where('ref_type', 'static')
            ->where(function ($query) use ($assetId): void {
                $query->where('ref_id', $assetId)
                    ->orWhere('ref_id', new ObjectId($assetId));
            });

        $this->assertSame(1, $projectionQuery->count());
        $projection = $projectionQuery->firstOrFail();

        $this->assertSame('image', data_get($projection->visual, 'mode'));
        $this->assertSame(
            'https://cdn.example.com/static-cover.png',
            data_get($projection->visual, 'image_uri')
        );
        $this->assertSame('item_media', data_get($projection->visual, 'source'));
    }

    public function test_static_profile_type_update_type_asset_visual_change_rematerializes_projection_visual(): void
    {
        Storage::fake('public');
        StaticProfileType::query()->delete();
        StaticAsset::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'beach',
            'label' => 'Beach',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#2266AA',
                'icon_color' => '#FFFFFF',
            ],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $asset = StaticAsset::create([
            'profile_type' => 'beach',
            'display_name' => 'Beach One',
            'avatar_url' => 'https://cdn.example.com/static-avatar.png',
            'cover_url' => 'https://cdn.example.com/static-cover.png',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => new ObjectId((string) $asset->_id),
            'source_checkpoint' => 1,
            'name' => 'Beach One',
            'category' => 'beach',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#2266AA',
                'icon_color' => '#FFFFFF',
                'source' => 'type_definition',
            ],
        ]);

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}static_profile_types/beach",
            [
                '_method' => 'PATCH',
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                ],
                'type_asset' => UploadedFile::fake()->image('beach-type.png', 256, 256),
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.visual.mode', 'image');
        $response->assertJsonPath('data.visual.image_source', 'type_asset');
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'type_asset');
        $typeAssetUrl = $response->json('data.visual.image_url');
        $this->assertIsString($typeAssetUrl);
        $this->assertStringContainsString('/api/v1/media/static-profile-types/', $typeAssetUrl);

        $model = StaticProfileType::query()->where('type', 'beach')->firstOrFail();
        $this->assertTypeAssetStored((string) $model->getKey(), 'static_profile_types');
        $assetId = (string) $asset->_id;
        $projectionQuery = MapPoi::query()
            ->where('ref_type', 'static')
            ->where(function ($query) use ($assetId): void {
                $query->where('ref_id', $assetId)
                    ->orWhere('ref_id', new ObjectId($assetId));
            });

        $this->assertSame(1, $projectionQuery->count());
        $projection = $projectionQuery->firstOrFail();

        $this->assertSame('image', data_get($projection->visual, 'mode'));
        $this->assertSame($typeAssetUrl, data_get($projection->visual, 'image_uri'));
        $this->assertSame('type_definition', data_get($projection->visual, 'source'));
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

    private function getMultipartHeaders(): array
    {
        return [
            ...$this->getHeaders(),
            'Content-Type' => 'multipart/form-data',
        ];
    }

    private function assertTypeAssetStored(string $typeId, string $directory): string
    {
        $needle = "/{$directory}/{$typeId}/type_asset.";

        foreach (Storage::disk('public')->allFiles() as $path) {
            if (str_contains($path, $needle)) {
                Storage::disk('public')->assertExists($path);

                return $path;
            }
        }

        $this->fail("Failed asserting that type asset exists for [{$typeId}] in [{$directory}].");
    }
}
