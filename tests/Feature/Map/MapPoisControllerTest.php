<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\StaticAssets\StaticAssetManagementService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\StaticProfileType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantSettings;
use App\Application\Accounts\AccountUserService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class MapPoisControllerTest extends TestCaseTenant
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
    private AccountUserService $userService;
    private AccountUser $user;

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

        MapPoi::query()->delete();
        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
            ],
            'events' => [
                'default_duration_hours' => 3,
            ],
        ]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
        ]);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['account-users:view']);

        Sanctum::actingAs($this->user, ['account-users:view']);
    }

    public function testMapPoisRequiresAuth(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $response = $this->getJson("{$this->base_api_tenant}map/pois");
        $response->assertStatus(401);
    }

    public function testMapPoisReturnsStacks(): void
    {
        $location = $this->point(-40.0, -20.0);
        $exactKey = $this->exactKey($location);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-1',
            'ref_slug' => 'event-one',
            'ref_path' => '/event/event-one',
            'name' => 'Event One',
            'category' => 'event',
            'location' => $location,
            'priority' => 80,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-1',
            'ref_slug' => 'static-one',
            'ref_path' => '/static/static-one',
            'name' => 'Static One',
            'category' => 'beach',
            'location' => $location,
            'priority' => 20,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/pois?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $stacks = $response->json('stacks');
        $this->assertNotEmpty($stacks);
        $this->assertEquals(2, $stacks[0]['stack_count']);
        $this->assertArrayHasKey('stack_key', $stacks[0]);
        $this->assertArrayHasKey('updated_at', $stacks[0]['top_poi']);
        $this->assertArrayNotHasKey('tags', $stacks[0]['top_poi']);
        $this->assertArrayNotHasKey('taxonomy_terms', $stacks[0]['top_poi']);
    }

    public function testMapNearReturnsCardsWithTagsAndTaxonomy(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-2',
            'ref_slug' => 'event-two',
            'ref_path' => '/event/event-two',
            'name' => 'Event Two',
            'subtitle' => 'Venue Name',
            'category' => 'event',
            'location' => $location,
            'priority' => 60,
            'is_active' => true,
            'time_start' => Carbon::now()->addDay(),
            'time_end' => Carbon::now()->addDay()->addHours(2),
            'tags' => ['live'],
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10");
        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertNotEmpty($items);
        $this->assertEquals('event-two', $items[0]['ref_slug']);
        $this->assertEquals('/event/event-two', $items[0]['ref_path']);
        $this->assertNotEmpty($items[0]['tags']);
        $this->assertNotEmpty($items[0]['taxonomy_terms']);
        $this->assertArrayHasKey('time_start', $items[0]);
        $this->assertArrayHasKey('time_end', $items[0]);
    }

    public function testMapNearReturnsNowFlagAndOccurrenceFacets(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-now',
            'projection_key' => 'event:event-now',
            'source_checkpoint' => 12345,
            'ref_slug' => 'event-now',
            'ref_path' => '/event/event-now',
            'name' => 'Event Now',
            'category' => 'event',
            'location' => $location,
            'priority' => 80,
            'is_active' => true,
            'is_happening_now' => true,
            'occurrence_facets' => [[
                'occurrence_id' => 'occ-1',
                'occurrence_slug' => 'occ-1',
                'starts_at' => Carbon::now()->subMinutes(20)->toISOString(),
                'ends_at' => null,
                'effective_end' => Carbon::now()->addHours(2)->toISOString(),
                'is_happening_now' => true,
            ]],
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10");
        $response->assertStatus(200);

        $item = $response->json('items.0');
        $this->assertTrue((bool) ($item['is_happening_now'] ?? false));
        $this->assertNotEmpty($item['occurrence_facets'] ?? []);
        $this->assertTrue((bool) data_get($item, 'occurrence_facets.0.is_happening_now', false));
    }

    public function testMapFiltersReturnsCatalogs(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-3',
            'ref_slug' => 'event-three',
            'ref_path' => '/event/event-three',
            'name' => 'Event Three',
            'category' => 'event',
            'location' => $location,
            'priority' => 60,
            'is_active' => true,
            'tags' => ['live'],
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/filters?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('categories'));
        $this->assertNotEmpty($response->json('tags'));
        $this->assertNotEmpty($response->json('taxonomy_terms'));
    }

    public function testMapPoisBoxIncludesPolygonDiscoveryScopeIntersections(): void
    {
        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-polygon',
            'projection_key' => 'event:event-polygon',
            'source_checkpoint' => 223344,
            'ref_slug' => 'event-polygon',
            'ref_path' => '/event/event-polygon',
            'name' => 'Polygon Event',
            'category' => 'event',
            'location' => $this->point(-45.0, -25.0),
            'discovery_scope' => [
                'type' => 'polygon',
                'polygon' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [-41.0, -21.0],
                        [-39.0, -21.0],
                        [-39.0, -19.0],
                        [-41.0, -19.0],
                        [-41.0, -21.0],
                    ]],
                ],
            ],
            'priority' => 60,
            'is_active' => true,
            'exact_key' => '-25.00000,-45.00000',
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/pois?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $stacks = $response->json('stacks') ?? [];
        $flatRefIds = [];
        foreach ($stacks as $stack) {
            $flatRefIds[] = data_get($stack, 'top_poi.ref_id');
        }

        $this->assertContains('event-polygon', $flatRefIds);
    }

    public function testStaticAssetCreationProjectsMapPoi(): void
    {
        StaticProfileType::query()->delete();
        Taxonomy::query()->delete();

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'beach',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_poi_enabled' => true,
                'has_taxonomies' => true,
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

        $service = $this->app->make(StaticAssetManagementService::class);
        $asset = $service->create([
            'profile_type' => 'poi',
            'display_name' => 'Praia Azul',
            'location' => ['lat' => -20.0, 'lng' => -40.0],
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
        ]);

        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'static')
                ->where('ref_id', (string) $asset->_id)
                ->exists()
        );
        $this->assertSame(
            'beach',
            MapPoi::query()
                ->where('ref_type', 'static')
                ->where('ref_id', (string) $asset->_id)
                ->first()?->category
        );
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Test Role',
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Test User',
            'email' => uniqid('map-user', true) . '@example.org',
            'password' => 'Secret!234',
            'timezone' => 'America/Sao_Paulo',
        ], (string) $role->_id);
    }

    /**
     * @param float $lng
     * @param float $lat
     * @return array<string, mixed>
     */
    private function point(float $lng, float $lat): array
    {
        return [
            'type' => 'Point',
            'coordinates' => [$lng, $lat],
        ];
    }

    private function exactKey(array $location): string
    {
        $coordinates = $location['coordinates'] ?? [0.0, 0.0];
        $lng = number_format((float) ($coordinates[0] ?? 0.0), 5, '.', '');
        $lat = number_format((float) ($coordinates[1] ?? 0.0), 5, '.', '');

        return $lat . ',' . $lng;
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
