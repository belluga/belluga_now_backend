<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class StaticAssetsControllerTest extends TestCaseTenant
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

    public function testStaticAssetCreateAndPublicRead(): void
    {
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();
        Taxonomy::query()->delete();
        TaxonomyTerm::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_poi_enabled' => true,
                'has_taxonomies' => true,
                'has_content' => true,
            ],
        ]);

        $taxonomy = Taxonomy::create([
            'slug' => 'cuisine',
            'name' => 'Cuisine',
            'applies_to' => ['static_asset'],
        ]);

        TaxonomyTerm::create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'italian',
            'name' => 'Italian',
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Praia Azul',
                'content' => 'Praia Azul page content',
                'location' => ['lat' => -20.0, 'lng' => -40.0],
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'italian'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $assetId = $response->json('data.id');
        $slug = $response->json('data.slug');

        $publicById = $this->getJson(
            "{$this->base_api_tenant}static_assets/{$assetId}",
            $this->getHeaders()
        );
        $publicById->assertStatus(200);
        $publicById->assertJsonPath('data.display_name', 'Praia Azul');

        $publicBySlug = $this->getJson(
            "{$this->base_api_tenant}static_assets/{$slug}",
            $this->getHeaders()
        );
        $publicBySlug->assertStatus(200);
        $publicBySlug->assertJsonPath('data.slug', $slug);
    }

    public function testStaticAssetRejectsDisallowedTaxonomy(): void
    {
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();
        Taxonomy::query()->delete();
        TaxonomyTerm::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_poi_enabled' => true,
                'has_taxonomies' => true,
            ],
        ]);

        $taxonomy = Taxonomy::create([
            'slug' => 'music',
            'name' => 'Music',
            'applies_to' => ['static_asset'],
        ]);

        TaxonomyTerm::create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'rock',
            'name' => 'Rock',
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Praia Verde',
                'location' => ['lat' => -20.0, 'lng' => -40.0],
                'taxonomy_terms' => [
                    ['type' => 'music', 'value' => 'rock'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testStaticAssetRequiresLocationWhenPoiEnabled(): void
    {
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Praia Sem Local',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testStaticAssetUpdate(): void
    {
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $created = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Praia Leste',
                'location' => ['lat' => -21.0, 'lng' => -41.0],
                'is_active' => true,
            ],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $assetId = $created->json('data.id');

        $updated = $this->patchJson(
            "{$this->base_tenant_api_admin}static_assets/{$assetId}",
            [
                'display_name' => 'Praia Leste Atualizada',
                'tags' => ['praia', 'sol'],
                'is_active' => false,
            ],
            $this->getHeaders()
        );

        $updated->assertStatus(200);
        $updated->assertJsonPath('data.display_name', 'Praia Leste Atualizada');
        $updated->assertJsonPath('data.is_active', false);
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
