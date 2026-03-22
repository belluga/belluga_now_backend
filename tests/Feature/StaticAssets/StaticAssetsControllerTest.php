<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_static_asset_create_and_public_read(): void
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

    public function test_static_asset_create_persists_avatar_and_cover_urls_without_file_upload(): void
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
                'display_name' => 'Praia URL',
                'location' => ['lat' => -20.0, 'lng' => -40.0],
                'avatar_url' => 'https://cdn.example.com/avatar.png',
                'cover_url' => 'https://cdn.example.com/cover.png',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $assetId = (string) $response->json('data.id');
        $response->assertJsonPath('data.avatar_url', 'https://cdn.example.com/avatar.png');
        $response->assertJsonPath('data.cover_url', 'https://cdn.example.com/cover.png');

        $publicById = $this->getJson(
            "{$this->base_api_tenant}static_assets/{$assetId}",
            $this->getHeaders()
        );
        $publicById->assertStatus(200);
        $publicById->assertJsonPath('data.avatar_url', 'https://cdn.example.com/avatar.png');
        $publicById->assertJsonPath('data.cover_url', 'https://cdn.example.com/cover.png');
    }

    public function test_static_asset_rejects_disallowed_taxonomy(): void
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

    public function test_static_asset_requires_location_when_poi_enabled(): void
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

    public function test_static_asset_create_stores_avatar_and_cover_uploads_with_retrievable_media_urls(): void
    {
        Storage::fake('public');
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

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Praia com Midia',
                'location' => ['lat' => -20.0, 'lng' => -40.0],
                'avatar' => UploadedFile::fake()->image('avatar.png', 512, 512),
                'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
            ],
        );

        $response->assertStatus(201);
        $assetId = (string) $response->json('data.id');
        $avatarUrl = (string) $response->json('data.avatar_url');
        $coverUrl = (string) $response->json('data.cover_url');

        $this->assertStringContainsString("/api/v1/media/static-assets/{$assetId}/avatar", $avatarUrl);
        $this->assertStringContainsString("/api/v1/media/static-assets/{$assetId}/cover", $coverUrl);

        $this->get("{$this->base_tenant_url}api/v1/media/static-assets/{$assetId}/avatar")->assertOk();
        $this->get("{$this->base_tenant_url}api/v1/media/static-assets/{$assetId}/cover")->assertOk();
        $this->get("{$this->base_tenant_url}static-assets/{$assetId}/avatar")->assertOk();
        $this->get("{$this->base_tenant_url}static-assets/{$assetId}/cover")->assertOk();
    }

    public function test_static_asset_update(): void
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
                'taxonomy_terms' => [],
                'is_active' => false,
            ],
            $this->getHeaders()
        );

        $updated->assertStatus(200);
        $updated->assertJsonPath('data.display_name', 'Praia Leste Atualizada');
        $updated->assertJsonPath('data.is_active', false);
    }

    public function test_static_assets_index_supports_text_search_query_param(): void
    {
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
                'has_content' => true,
            ],
        ]);

        $matching = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Sunset Boulevard Match',
                'content' => 'Festival jazz e sunset na orla',
                'location' => ['lat' => -20.0, 'lng' => -40.0],
            ],
            $this->getHeaders()
        );
        $matching->assertStatus(201);
        $matchingId = (string) $matching->json('data.id');

        $other = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'poi',
                'display_name' => 'Praia Sem Relacao',
                'content' => 'Texto diferente',
                'location' => ['lat' => -20.1, 'lng' => -40.1],
            ],
            $this->getHeaders()
        );
        $other->assertStatus(201);
        $otherId = (string) $other->json('data.id');

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}static_assets?search=sunset&page=1&per_page=20",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $ids = collect($response->json('data') ?? [])
            ->map(static fn (array $item): string => (string) ($item['id'] ?? ''))
            ->all();
        $this->assertContains($matchingId, $ids);
        $this->assertNotContains($otherId, $ids);

        $partialResponse = $this->getJson(
            "{$this->base_tenant_api_admin}static_assets?search=sunse&page=1&per_page=20",
            $this->getHeaders()
        );

        $partialResponse->assertStatus(200);
        $partialIds = collect($partialResponse->json('data') ?? [])
            ->map(static fn (array $item): string => (string) ($item['id'] ?? ''))
            ->all();
        $this->assertContains($matchingId, $partialIds);
        $this->assertNotContains($otherId, $partialIds);

        $containsResponse = $this->getJson(
            "{$this->base_tenant_api_admin}static_assets?search=nset&page=1&per_page=20",
            $this->getHeaders()
        );

        $containsResponse->assertStatus(200);
        $containsIds = collect($containsResponse->json('data') ?? [])
            ->map(static fn (array $item): string => (string) ($item['id'] ?? ''))
            ->all();
        $this->assertContains($matchingId, $containsIds);
        $this->assertNotContains($otherId, $containsIds);
    }

    private function getMultipartHeaders(): array
    {
        $headers = $this->getHeaders();
        unset($headers['Content-Type']);
        $headers['Accept'] = 'application/json';

        return $headers;
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
