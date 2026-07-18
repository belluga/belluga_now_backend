<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MongoDB\BSON\ObjectId;
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

    public function test_profile_type_index_lists_registry(): void
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

    public function test_profile_type_show_returns_definition_with_plural_label(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'labels' => [
                'singular' => 'Artist',
                'plural' => 'Artists',
            ],
            'allowed_taxonomies' => ['music_genre'],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types/artist",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.type', 'artist');
        $response->assertJsonPath('data.label', 'Artist');
        $response->assertJsonPath('data.labels.singular', 'Artist');
        $response->assertJsonPath('data.labels.plural', 'Artists');
    }

    public function test_profile_type_create(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue-create',
                'label' => 'Venue',
                'labels' => [
                    'singular' => 'Venue',
                    'plural' => 'Venues',
                ],
                'allowed_taxonomies' => ['cuisine'],
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'place',
                    'color' => '#FF8800',
                    'icon_color' => '#101010',
                ],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                    'is_reference_location_enabled' => true,
                    'has_nested_profile_groups' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'venue-create');
        $response->assertJsonPath('data.label', 'Venue');
        $response->assertJsonPath('data.labels.singular', 'Venue');
        $response->assertJsonPath('data.labels.plural', 'Venues');
        $response->assertJsonPath('data.capabilities.is_poi_enabled', true);
        $response->assertJsonPath('data.capabilities.is_reference_location_enabled', true);
        $response->assertJsonPath('data.capabilities.has_nested_profile_groups', true);
        $response->assertJsonPath('data.poi_visual.mode', 'icon');
        $response->assertJsonPath('data.poi_visual.icon', 'place');
        $response->assertJsonPath('data.poi_visual.color', '#FF8800');
        $response->assertJsonPath('data.poi_visual.icon_color', '#101010');
    }

    public function test_profile_type_create_repairs_public_environment_snapshot_synchronously(): void
    {
        TenantProfileType::query()->delete();
        $this->seedUsableTenantEnvironmentSnapshot('test_profile_type_create_sync_snapshot_seed');

        $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue-sync-create',
                'label' => 'Venue Sync Create',
                'labels' => [
                    'singular' => 'Venue Sync Create',
                    'plural' => 'Venue Sync Creates',
                ],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_discoverable' => true,
                    'is_publicly_navigable' => true,
                ],
            ],
            $this->getHeaders()
        )->assertStatus(201);

        $environmentType = $this->publicEnvironmentProfileType('venue-sync-create');

        $this->assertIsArray($environmentType);
        $this->assertSame('Venue Sync Create', $environmentType['label'] ?? null);
        $this->assertTrue((bool) data_get($environmentType, 'capabilities.is_queryable', false));
        $this->assertTrue((bool) data_get($environmentType, 'capabilities.is_publicly_discoverable', false));
    }

    public function test_profile_type_create_disables_reference_location_when_poi_is_disabled(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'hotel',
                'label' => 'Hotel',
                'labels' => [
                    'singular' => 'Hotel',
                    'plural' => 'Hotels',
                ],
                'allowed_taxonomies' => ['hospitality'],
                'capabilities' => [
                    'is_poi_enabled' => false,
                    'is_reference_location_enabled' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.capabilities.is_poi_enabled', false);
        $response->assertJsonPath('data.capabilities.is_reference_location_enabled', false);

        $model = TenantProfileType::query()->where('type', 'hotel')->firstOrFail();
        $this->assertFalse((bool) ($model->capabilities['is_reference_location_enabled'] ?? false));
    }

    public function test_profile_type_create_keeps_nested_groups_capability_independent(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'expo',
                'label' => 'Expo',
                'capabilities' => [
                    'is_poi_enabled' => false,
                    'has_events' => false,
                    'has_nested_profile_groups' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.capabilities.is_poi_enabled', false);
        $response->assertJsonPath('data.capabilities.has_events', false);
        $response->assertJsonPath('data.capabilities.has_nested_profile_groups', true);

        $model = TenantProfileType::query()->where('type', 'expo')->firstOrFail();
        $this->assertTrue((bool) ($model->capabilities['has_nested_profile_groups'] ?? false));
    }

    public function test_profile_type_create_persists_independent_queryability_discovery_and_public_navigation_flags(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'delegate',
                'label' => 'Delegate',
                'capabilities' => [
                    'is_queryable' => false,
                    'is_publicly_navigable' => true,
                    'is_publicly_discoverable' => true,
                    'is_favoritable' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.capabilities.is_queryable', false);
        $response->assertJsonPath('data.capabilities.is_publicly_navigable', true);
        $response->assertJsonPath('data.capabilities.is_publicly_discoverable', true);
        $response->assertJsonPath('data.capabilities.is_favoritable', true);

        $model = TenantProfileType::query()->where('type', 'delegate')->firstOrFail();
        $this->assertFalse((bool) ($model->capabilities['is_queryable'] ?? true));
        $this->assertTrue((bool) ($model->capabilities['is_publicly_navigable'] ?? false));
        $this->assertTrue((bool) ($model->capabilities['is_publicly_discoverable'] ?? false));
        $this->assertTrue((bool) ($model->capabilities['is_favoritable'] ?? false));
    }

    public function test_publicly_navigable_scope_is_fail_closed_when_flag_is_omitted(): void
    {
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'direct-only',
            'label' => 'Direct Only',
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_discoverable' => false,
            ],
        ]);

        TenantProfileType::create([
            'type' => 'closed-detail',
            'label' => 'Closed Detail',
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_publicly_navigable' => false,
            ],
        ]);

        $publiclyNavigableTypes = TenantProfileType::query()
            ->publiclyNavigable()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->values()
            ->all();

        $this->assertNotContains('direct-only', $publiclyNavigableTypes);
        $this->assertNotContains('closed-detail', $publiclyNavigableTypes);
    }

    public function test_public_catalog_and_public_poi_scopes_respect_public_discoverability_independently_from_favoritable(): void
    {
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'public-poi',
            'label' => 'Public Poi',
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'public-non-poi',
            'label' => 'Public Non Poi',
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'non-favoritable-poi',
            'label' => 'Non Favoritable Poi',
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => false,
                'is_poi_enabled' => true,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'hidden-poi',
            'label' => 'Hidden Poi',
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => false,
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $publicCatalogTypes = TenantProfileType::query()
            ->publicCatalog()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->values()
            ->all();
        $publicPoiCatalogTypes = TenantProfileType::query()
            ->publicPoiCatalog()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            ['non-favoritable-poi', 'public-non-poi', 'public-poi'],
            $publicCatalogTypes
        );
        $this->assertEqualsCanonicalizing(['non-favoritable-poi', 'public-poi'], $publicPoiCatalogTypes);
    }

    public function test_queryability_and_public_navigation_backfill_repairs_missing_flags_without_overwriting_explicit_values(): void
    {
        TenantProfileType::query()->delete();

        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'capabilities' => [
                'is_favoritable' => true,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'capabilities' => [
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'explicit-hidden',
            'label' => 'Explicit Hidden',
            'capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
            ],
        ]);

        $migration = require base_path(
            'database/migrations/tenants/2026_06_06_000100_backfill_profile_type_queryability_and_public_navigation.php'
        );
        $migration->up();

        $personal = TenantProfileType::query()->where('type', 'personal')->firstOrFail();
        $venue = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $explicitHidden = TenantProfileType::query()->where('type', 'explicit-hidden')->firstOrFail();

        $this->assertFalse((bool) data_get($personal->capabilities, 'is_queryable', true));
        $this->assertFalse((bool) data_get($personal->capabilities, 'is_publicly_discoverable', true));
        $this->assertFalse((bool) data_get($personal->capabilities, 'is_publicly_navigable', true));
        $this->assertTrue((bool) data_get($venue->capabilities, 'is_queryable', false));
        $this->assertTrue((bool) data_get($venue->capabilities, 'is_publicly_discoverable', false));
        $this->assertTrue((bool) data_get($venue->capabilities, 'is_publicly_navigable', false));
        $this->assertFalse((bool) data_get($explicitHidden->capabilities, 'is_queryable', true));
        $this->assertFalse((bool) data_get($explicitHidden->capabilities, 'is_publicly_discoverable', true));
        $this->assertFalse((bool) data_get($explicitHidden->capabilities, 'is_publicly_navigable', true));
    }

    public function test_profile_type_create_validation(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_profile_type_create_rejects_invalid_poi_visual_icon_without_color_and_icon_color(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
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

    public function test_profile_type_create_accepts_canonical_visual_type_asset_upload(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'gallery',
                'label' => 'Gallery',
                'allowed_taxonomies' => ['art_style'],
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                    'color' => '#5E35B1',
                ],
                'type_asset' => UploadedFile::fake()->image('gallery.png', 320, 320),
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                ],
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.visual.mode', 'image');
        $response->assertJsonPath('data.visual.image_source', 'type_asset');
        $response->assertJsonPath('data.visual.color', '#5E35B1');
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'type_asset');
        $response->assertJsonPath('data.poi_visual.color', '#5E35B1');
        $typeAssetUrl = $response->json('data.visual.image_url');
        $this->assertIsString($typeAssetUrl);
        $this->assertStringContainsString('/api/v1/media/account-profile-types/', $typeAssetUrl);
        $this->assertSame($typeAssetUrl, $response->json('data.poi_visual.image_url'));

        $model = TenantProfileType::query()->where('type', 'gallery')->firstOrFail();
        $this->assertSame('#5E35B1', data_get($model->visual, 'color'));
        $this->assertTypeAssetStored((string) $model->getKey(), 'account_profile_types');
    }

    public function test_profile_type_create_rejects_type_asset_visual_without_upload(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'gallery-missing',
                'label' => 'Gallery Missing',
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

    public function test_profile_type_create_rejects_duplicate_type(): void
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

    public function test_profile_type_create_validates_allowed_taxonomies_length(): void
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

    public function test_profile_type_update(): void
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

    public function test_profile_type_update_uses_route_param(): void
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

    public function test_profile_type_update_preserves_existing_capabilities_when_toggling_nested_groups(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
                'has_events' => true,
                'has_nested_profile_groups' => false,
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/venue",
            [
                'capabilities' => [
                    'has_nested_profile_groups' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.capabilities.is_favoritable', true);
        $response->assertJsonPath('data.capabilities.is_publicly_discoverable', true);
        $response->assertJsonPath('data.capabilities.is_poi_enabled', true);
        $response->assertJsonPath('data.capabilities.has_events', true);
        $response->assertJsonPath('data.capabilities.has_nested_profile_groups', true);

        $model = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $this->assertTrue((bool) ($model->capabilities['is_favoritable'] ?? false));
        $this->assertTrue((bool) ($model->capabilities['is_publicly_discoverable'] ?? false));
        $this->assertTrue((bool) ($model->capabilities['is_poi_enabled'] ?? false));
        $this->assertTrue((bool) ($model->capabilities['has_events'] ?? false));
        $this->assertTrue((bool) ($model->capabilities['has_nested_profile_groups'] ?? false));
    }

    public function test_profile_type_map_poi_projection_impact_returns_projection_count(): void
    {
        TenantProfileType::query()->delete();
        AccountProfile::query()->delete();
        MapPoi::query()->delete();

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $first = AccountProfile::create([
            'account_id' => 'account-1',
            'profile_type' => 'venue',
            'display_name' => 'Venue One',
            'is_active' => true,
        ]);
        $second = AccountProfile::create([
            'account_id' => 'account-2',
            'profile_type' => 'venue',
            'display_name' => 'Venue Two',
            'is_active' => true,
        ]);
        $other = AccountProfile::create([
            'account_id' => 'account-3',
            'profile_type' => 'artist',
            'display_name' => 'Artist',
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $first->_id,
            'name' => 'Venue One',
            'category' => 'venue',
            'is_active' => true,
        ]);
        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $second->_id,
            'name' => 'Venue Two',
            'category' => 'venue',
            'is_active' => true,
        ]);
        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => (string) $other->_id,
            'name' => 'Artist',
            'category' => 'artist',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types/venue/map_poi_projection_impact",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.profile_type', 'venue');
        $response->assertJsonPath('data.projection_count', 2);
    }

    public function test_profile_type_update_rejects_type_rename_when_profiles_reference_current_type(): void
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
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
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

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);

        $this->assertTrue(TenantProfileType::query()->where('type', 'personal')->exists());
        $this->assertFalse(TenantProfileType::query()->where('type', 'creator')->exists());
        $this->assertSame(
            'personal',
            (string) (AccountProfile::query()->findOrFail($profile->_id)->profile_type ?? '')
        );
        $this->assertSame(
            'personal',
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

    public function test_profile_type_update_disables_poi_and_hard_deletes_related_projections(): void
    {
        TenantProfileType::query()->delete();
        AccountProfile::query()->delete();
        MapPoi::query()->delete();

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => 'account-hard-delete',
            'profile_type' => 'venue',
            'display_name' => 'Venue One',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => new ObjectId((string) $profile->_id),
            'source_checkpoint' => 1,
            'name' => 'Venue One',
            'category' => 'venue',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/venue",
            [
                'capabilities' => [
                    'is_poi_enabled' => false,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.capabilities.is_poi_enabled', false);
        $profileId = (string) $profile->_id;
        $remaining = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where(function ($query) use ($profileId): void {
                $query->where('ref_id', $profileId)
                    ->orWhere('ref_id', new ObjectId($profileId));
            })
            ->count();

        $this->assertSame(0, $remaining);
    }

    public function test_profile_type_update_repairs_public_environment_snapshot_synchronously(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue-sync-update',
            'label' => 'Venue Sync Old',
            'labels' => [
                'singular' => 'Venue Sync Old',
                'plural' => 'Venue Sync Olds',
            ],
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_discoverable' => false,
                'is_publicly_navigable' => false,
            ],
        ]);

        $this->seedUsableTenantEnvironmentSnapshot('test_profile_type_update_sync_snapshot_seed');

        $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/venue-sync-update",
            [
                'label' => 'Venue Sync Updated',
                'labels' => [
                    'singular' => 'Venue Sync Updated',
                    'plural' => 'Venue Sync Updateds',
                ],
                'capabilities' => [
                    'is_publicly_discoverable' => true,
                    'is_publicly_navigable' => true,
                    'has_contact_channels' => true,
                ],
            ],
            $this->getHeaders()
        )->assertStatus(200);

        $environmentType = $this->publicEnvironmentProfileType('venue-sync-update');

        $this->assertIsArray($environmentType);
        $this->assertSame('Venue Sync Updated', $environmentType['label'] ?? null);
        $this->assertTrue((bool) data_get($environmentType, 'capabilities.is_publicly_discoverable', false));
        $this->assertTrue((bool) data_get($environmentType, 'capabilities.is_publicly_navigable', false));
        $this->assertTrue((bool) data_get($environmentType, 'capabilities.has_contact_channels', false));
    }

    public function test_profile_type_update_poi_visual_change_rematerializes_projection_visual(): void
    {
        TenantProfileType::query()->delete();
        AccountProfile::query()->delete();
        MapPoi::query()->delete();

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#AA3300',
                'icon_color' => '#FFFFFF',
            ],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => 'account-visual-change',
            'profile_type' => 'venue',
            'display_name' => 'Venue One',
            'avatar_url' => 'https://cdn.example.com/account-profile-avatar.png',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => new ObjectId((string) $profile->_id),
            'source_checkpoint' => 1,
            'name' => 'Venue One',
            'category' => 'venue',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#AA3300',
                'icon_color' => '#FFFFFF',
                'source' => 'type_definition',
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/venue",
            [
                'poi_visual' => [
                    'mode' => 'image',
                    'image_source' => 'avatar',
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'avatar');
        $profileId = (string) $profile->_id;
        $projectionQuery = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where(function ($query) use ($profileId): void {
                $query->where('ref_id', $profileId)
                    ->orWhere('ref_id', new ObjectId($profileId));
            });

        $this->assertSame(1, $projectionQuery->count());
        $projection = $projectionQuery->firstOrFail();

        $this->assertSame('image', data_get($projection->visual, 'mode'));
        $this->assertSame(
            'https://cdn.example.com/account-profile-avatar.png',
            data_get($projection->visual, 'image_uri')
        );
        $this->assertSame('item_media', data_get($projection->visual, 'source'));
    }

    public function test_profile_type_update_type_asset_visual_change_rematerializes_projection_visual(): void
    {
        Storage::fake('public');
        TenantProfileType::query()->delete();
        AccountProfile::query()->delete();
        MapPoi::query()->delete();

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#AA3300',
                'icon_color' => '#FFFFFF',
            ],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => 'account-type-asset-change',
            'profile_type' => 'venue',
            'display_name' => 'Venue One',
            'avatar_url' => 'https://cdn.example.com/account-profile-avatar.png',
            'cover_url' => 'https://cdn.example.com/account-profile-cover.png',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => new ObjectId((string) $profile->_id),
            'source_checkpoint' => 1,
            'name' => 'Venue One',
            'category' => 'venue',
            'is_active' => true,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'visual' => [
                'mode' => 'icon',
                'icon' => 'place',
                'color' => '#AA3300',
                'icon_color' => '#FFFFFF',
                'source' => 'type_definition',
            ],
        ]);

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profile_types/venue",
            [
                '_method' => 'PATCH',
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                ],
                'type_asset' => UploadedFile::fake()->image('venue-type.png', 256, 256),
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.visual.mode', 'image');
        $response->assertJsonPath('data.visual.image_source', 'type_asset');
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'type_asset');
        $typeAssetUrl = $response->json('data.visual.image_url');
        $this->assertIsString($typeAssetUrl);
        $this->assertStringContainsString('/api/v1/media/account-profile-types/', $typeAssetUrl);
        $typeAssetUri = (string) parse_url($typeAssetUrl, PHP_URL_PATH);
        $typeAssetQuery = parse_url($typeAssetUrl, PHP_URL_QUERY);
        if (is_string($typeAssetQuery) && $typeAssetQuery !== '') {
            $typeAssetUri .= '?'.$typeAssetQuery;
        }

        $model = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $this->assertTypeAssetStored((string) $model->getKey(), 'account_profile_types');
        $profileId = (string) $profile->_id;
        $projectionQuery = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where(function ($query) use ($profileId): void {
                $query->where('ref_id', $profileId)
                    ->orWhere('ref_id', new ObjectId($profileId));
            });

        $this->assertSame(1, $projectionQuery->count());
        $projection = $projectionQuery->firstOrFail();

        $this->assertSame('image', data_get($projection->visual, 'mode'));
        $this->assertSame($typeAssetUri, data_get($projection->visual, 'image_uri'));
        $this->assertSame('type_definition', data_get($projection->visual, 'source'));
    }

    public function test_profile_type_update_rejects_duplicate_type_rename(): void
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

    public function test_profile_type_update_disables_reference_location_when_poi_is_turned_off(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
                'is_reference_location_enabled' => true,
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/venue",
            [
                'capabilities' => [
                    'is_poi_enabled' => false,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.capabilities.is_poi_enabled', false);
        $response->assertJsonPath('data.capabilities.is_reference_location_enabled', false);

        $model = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $this->assertFalse((bool) ($model->capabilities['is_reference_location_enabled'] ?? false));
    }

    public function test_profile_type_index_exposes_effective_reference_location_capability(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'hotel',
            'label' => 'Hotel',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => false,
                'is_reference_location_enabled' => true,
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.capabilities.is_poi_enabled', false);
        $response->assertJsonPath('data.0.capabilities.is_reference_location_enabled', false);
    }

    public function test_profile_type_delete(): void
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

    public function test_profile_type_delete_repairs_public_environment_snapshot_synchronously(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist-sync-delete',
            'label' => 'Artist Sync Delete',
            'labels' => [
                'singular' => 'Artist Sync Delete',
                'plural' => 'Artist Sync Deletes',
            ],
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_publicly_navigable' => true,
            ],
        ]);

        $this->seedUsableTenantEnvironmentSnapshot('test_profile_type_delete_sync_snapshot_seed');
        $this->assertIsArray($this->publicEnvironmentProfileType('artist-sync-delete'));

        $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profile_types/artist-sync-delete",
            [],
            $this->getHeaders()
        )->assertStatus(200);

        $this->assertNull($this->publicEnvironmentProfileType('artist-sync-delete'));
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

    private function seedUsableTenantEnvironmentSnapshot(string $reason): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        app(TenantEnvironmentSnapshotService::class)->repair(
            $tenant->fresh() ?? $tenant,
            $reason,
            ['trigger' => 'account_profile_type_controller_test'],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicEnvironmentProfileType(string $type): ?array
    {
        $response = $this->getJson("{$this->base_api_tenant}environment");
        $response->assertStatus(200);

        foreach (($response->json('profile_types') ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if ((string) ($entry['type'] ?? '') === $type) {
                return $entry;
            }
        }

        return null;
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
