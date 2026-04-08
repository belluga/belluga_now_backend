<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\StaticAssets\StaticAssetManagementService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\StaticProfileType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use App\Models\Tenants\TenantSettings;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use MongoDB\Model\BSONDocument;
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

    public function test_map_pois_requires_auth(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $response = $this->getJson("{$this->base_api_tenant}map/pois");
        $response->assertStatus(401);
    }

    public function test_map_poi_lookup_returns_poi_by_typed_reference(): void
    {
        $location = $this->point(-40.0, -20.0);
        $exactKey = $this->exactKey($location);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-lookup',
            'ref_slug' => 'event-lookup',
            'ref_path' => '/agenda/evento/event-lookup',
            'name' => 'Event Lookup',
            'subtitle' => 'Lookup subtitle',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 70,
            'is_active' => true,
            'visual' => [
                'mode' => 'icon',
                'icon' => 'event',
                'color' => '#3355AA',
                'icon_color' => '#FFFFFF',
                'source' => 'type_definition',
            ],
            'exact_key' => $exactKey,
        ]);
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-same-stack',
            'ref_slug' => 'static-same-stack',
            'ref_path' => '/static/static-same-stack',
            'name' => 'Static Same Stack',
            'category' => 'beach',
            'source_type' => 'poi',
            'location' => $location,
            'priority' => 120,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}map/pois/lookup?ref_type=event&ref_id=event-lookup"
        );
        $response->assertStatus(200);

        $this->assertNotSame('', (string) $response->json('tenant_id'));
        $response->assertJsonPath('poi.ref_type', 'event');
        $response->assertJsonPath('poi.ref_id', 'event-lookup');
        $response->assertJsonPath('poi.ref_slug', 'event-lookup');
        $response->assertJsonPath('poi.ref_path', '/agenda/evento/event-lookup');
        $response->assertJsonPath('poi.stack_key', $exactKey);
        $response->assertJsonPath('poi.stack_count', 1);
        $response->assertJsonPath('poi.visual.mode', 'icon');
        $response->assertJsonPath('poi.visual.icon', 'event');
        $response->assertJsonPath('poi.visual.color', '#3355AA');
        $response->assertJsonPath('poi.visual.icon_color', '#FFFFFF');
        $response->assertJsonPath('poi.visual.source', 'type_definition');
    }

    public function test_map_poi_lookup_returns_not_found_for_unknown_reference(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}map/pois/lookup?ref_type=event&ref_id=event-missing"
        );
        $response->assertStatus(404);
        $response->assertJsonPath('message', 'POI not found.');
    }

    public function test_map_pois_event_dominance_hides_same_point_static_poi_from_stack(): void
    {
        $location = $this->point(-40.0, -20.0);
        $exactKey = $this->exactKey($location);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-1',
            'ref_slug' => 'event-one',
            'ref_path' => '/agenda/evento/event-one',
            'name' => 'Event One',
            'subtitle' => 'Live tonight',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 80,
            'is_active' => true,
            'visual' => [
                'mode' => 'icon',
                'icon' => 'celebration',
                'color' => '#FF2200',
                'icon_color' => '#FFFFFF',
                'source' => 'type_definition',
            ],
            'exact_key' => $exactKey,
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-1',
            'ref_slug' => 'static-one',
            'ref_path' => '/static/static-one',
            'name' => 'Static One',
            'category' => 'beach',
            'source_type' => 'poi',
            'location' => $location,
            'priority' => 200,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/pois?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $stacks = $response->json('stacks');
        $this->assertNotEmpty($stacks);
        $this->assertEquals(1, $stacks[0]['stack_count']);
        $this->assertArrayHasKey('stack_key', $stacks[0]);
        $this->assertSame('event', $stacks[0]['top_poi']['ref_type'] ?? null);
        $this->assertArrayHasKey('updated_at', $stacks[0]['top_poi']);
        $this->assertArrayHasKey('title', $stacks[0]['top_poi']);
        $this->assertArrayHasKey('subtitle', $stacks[0]['top_poi']);
        $this->assertArrayHasKey('ref_slug', $stacks[0]['top_poi']);
        $this->assertArrayHasKey('ref_path', $stacks[0]['top_poi']);
        $this->assertArrayHasKey('source_type', $stacks[0]['top_poi']);
        $this->assertArrayHasKey('visual', $stacks[0]['top_poi']);
        $this->assertSame('icon', $stacks[0]['top_poi']['visual']['mode'] ?? null);
        $this->assertSame('#FFFFFF', $stacks[0]['top_poi']['visual']['icon_color'] ?? null);
        $this->assertArrayNotHasKey('tags', $stacks[0]['top_poi']);
        $this->assertArrayNotHasKey('taxonomy_terms', $stacks[0]['top_poi']);
    }

    public function test_map_pois_stack_key_keeps_multiple_events_and_hides_same_point_static_poi(): void
    {
        $location = $this->point(-40.0, -20.0);
        $exactKey = $this->exactKey($location);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-alpha',
            'ref_slug' => 'event-alpha',
            'ref_path' => '/agenda/evento/event-alpha',
            'name' => 'Event Alpha',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 40,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);
        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-beta',
            'ref_slug' => 'event-beta',
            'ref_path' => '/agenda/evento/event-beta',
            'name' => 'Event Beta',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 80,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-ignored',
            'ref_slug' => 'static-ignored',
            'ref_path' => '/static/static-ignored',
            'name' => 'Static Ignored',
            'category' => 'beach',
            'source_type' => 'poi',
            'location' => $location,
            'priority' => 500,
            'is_active' => true,
            'exact_key' => $exactKey,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}map/pois?stack_key={$exactKey}"
        );
        $response->assertStatus(200);

        $response->assertJsonPath('stacks.0.stack_count', 2);
        $items = collect($response->json('stacks.0.items') ?? []);
        $this->assertCount(2, $items);
        $this->assertSame(
            ['event', 'event'],
            $items->map(static fn (array $item): string => (string) ($item['ref_type'] ?? ''))->all()
        );
        $this->assertSame(
            ['event-beta', 'event-alpha'],
            $items->map(static fn (array $item): string => (string) ($item['ref_id'] ?? ''))->all()
        );
    }

    public function test_map_pois_hides_local_only_stack_within_event_dominance_radius(): void
    {
        $eventLocation = $this->point(-40.00000, -20.00000);
        $nearLocalLocation = $this->point(-40.00000, -20.00030);
        $farLocalLocation = $this->point(-40.00000, -20.00150);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-anchor',
            'ref_slug' => 'event-anchor',
            'ref_path' => '/agenda/evento/event-anchor',
            'name' => 'Event Anchor',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $eventLocation,
            'priority' => 60,
            'is_active' => true,
            'exact_key' => $this->exactKey($eventLocation),
        ]);
        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => 'local-near',
            'ref_slug' => 'local-near',
            'ref_path' => '/parceiro/local-near',
            'name' => 'Local Near',
            'category' => 'restaurant',
            'source_type' => 'restaurant',
            'location' => $nearLocalLocation,
            'priority' => 999,
            'is_active' => true,
            'exact_key' => $this->exactKey($nearLocalLocation),
        ]);
        MapPoi::create([
            'ref_type' => 'account_profile',
            'ref_id' => 'local-far',
            'ref_slug' => 'local-far',
            'ref_path' => '/parceiro/local-far',
            'name' => 'Local Far',
            'category' => 'restaurant',
            'source_type' => 'restaurant',
            'location' => $farLocalLocation,
            'priority' => 999,
            'is_active' => true,
            'exact_key' => $this->exactKey($farLocalLocation),
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}map/pois?ne_lat=-19.99&ne_lng=-39.99&sw_lat=-20.01&sw_lng=-40.01"
        );
        $response->assertStatus(200);

        $refIds = collect($response->json('stacks') ?? [])
            ->map(static fn (array $stack): string => (string) data_get($stack, 'top_poi.ref_id', ''))
            ->all();

        $this->assertContains('event-anchor', $refIds);
        $this->assertContains('local-far', $refIds);
        $this->assertNotContains('local-near', $refIds);
    }

    public function test_map_pois_exposes_visual_from_bson_type_projection_chain(): void
    {
        TenantProfileType::query()->delete();
        AccountProfile::query()->delete();
        MapPoi::query()->delete();

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'poi_visual' => new BSONDocument([
                'mode' => 'icon',
                'icon' => 'restaurant',
                'color' => '#eb2528',
                'icon_color' => '#ffffff',
            ]),
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'BSON Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);

        $this->app->make(MapPoiProjectionService::class)->upsertFromAccountProfile(
            $profile->fresh()
        );

        $response = $this->getJson("{$this->base_api_tenant}map/pois?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $response->assertJsonPath('stacks.0.top_poi.ref_type', 'account_profile');
        $response->assertJsonPath('stacks.0.top_poi.ref_id', (string) $profile->_id);
        $response->assertJsonPath('stacks.0.top_poi.visual.mode', 'icon');
        $response->assertJsonPath('stacks.0.top_poi.visual.icon', 'restaurant');
        $response->assertJsonPath('stacks.0.top_poi.visual.color', '#EB2528');
        $response->assertJsonPath('stacks.0.top_poi.visual.icon_color', '#FFFFFF');
    }

    public function test_map_near_returns_cards_with_tags_and_taxonomy(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-2',
            'ref_slug' => 'event-two',
            'ref_path' => '/agenda/evento/event-two',
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
        $this->assertEquals('/agenda/evento/event-two', $items[0]['ref_path']);
        $this->assertNotEmpty($items[0]['tags']);
        $this->assertNotEmpty($items[0]['taxonomy_terms']);
        $this->assertArrayHasKey('time_start', $items[0]);
        $this->assertArrayHasKey('time_end', $items[0]);
    }

    public function test_map_near_supports_partial_text_search(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-thales',
            'ref_slug' => 'thales-hub',
            'ref_path' => '/static/thales-hub',
            'name' => 'Thales Hub',
            'category' => 'poi',
            'source_type' => 'poi',
            'location' => $location,
            'priority' => 50,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-other',
            'ref_slug' => 'bruno-hub',
            'ref_path' => '/static/bruno-hub',
            'name' => 'Bruno Hub',
            'category' => 'poi',
            'source_type' => 'poi',
            'location' => $location,
            'priority' => 40,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}map/near?origin_lat=-20.0&origin_lng=-40.0&search=ales&page=1&page_size=10"
        );
        $response->assertStatus(200);

        $items = collect($response->json('items') ?? []);
        $slugs = $items->map(static fn (array $item): string => (string) ($item['ref_slug'] ?? ''))->all();

        $this->assertContains('thales-hub', $slugs);
        $this->assertNotContains('bruno-hub', $slugs);
    }

    public function test_map_near_returns_now_flag_and_occurrence_facets(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-now',
            'projection_key' => 'event:event-now',
            'source_checkpoint' => 12345,
            'ref_slug' => 'event-now',
            'ref_path' => '/agenda/evento/event-now',
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

    public function test_map_filters_returns_catalogs(): void
    {
        $location = $this->point(-40.0, -20.0);

        TenantSettings::query()->firstOrFail()->update([
            'map_ui' => [
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
                'filters' => [
                    [
                        'key' => 'events',
                        'label' => 'Eventos em destaque',
                        'image_uri' => 'https://tenant-zeta.test/map-filters/events/image?v=1710000000',
                        'override_marker' => true,
                        'marker_override' => [
                            'mode' => 'icon',
                            'icon' => 'celebration',
                            'color' => '#FF2200',
                            'icon_color' => '#101010',
                        ],
                        'query' => [
                            'source' => 'event',
                        ],
                    ],
                    [
                        'key' => 'praia',
                        'label' => 'Praias',
                        'override_marker' => true,
                        'marker_override' => [
                            'mode' => 'image',
                            'image_uri' => 'https://tenant-zeta.test/map-filters/praia/image?v=1710000002',
                        ],
                        'query' => [
                            'source' => 'static_asset',
                            'types' => ['beach_spot'],
                        ],
                    ],
                ],
            ],
        ]);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-3',
            'ref_slug' => 'event-three',
            'ref_path' => '/agenda/evento/event-three',
            'name' => 'Event Three',
            'category' => 'event',
            'source_type' => 'show',
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
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-beach',
            'ref_slug' => 'praia-azul',
            'ref_path' => '/static/praia-azul',
            'name' => 'Praia Azul',
            'category' => 'beach',
            'source_type' => 'beach_spot',
            'location' => $location,
            'priority' => 40,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/filters?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $this->assertNotEmpty($response->json('categories'));
        $this->assertNotEmpty($response->json('tags'));
        $this->assertNotEmpty($response->json('taxonomy_terms'));
        $response->assertJsonPath('categories.0.key', 'events');
        $response->assertJsonPath('categories.0.label', 'Eventos em destaque');
        $imageUri = (string) $response->json('categories.0.image_uri');
        $this->assertNotSame('', $imageUri);
        $this->assertSame('/api/v1/media/map-filters/events', parse_url($imageUri, PHP_URL_PATH));
        parse_str((string) parse_url($imageUri, PHP_URL_QUERY), $imageQuery);
        $this->assertSame('1710000000', $imageQuery['v'] ?? null);
        $response->assertJsonPath('categories.0.query.source', 'event');
        $response->assertJsonPath('categories.0.override_marker', true);
        $response->assertJsonPath('categories.0.marker_override.mode', 'icon');
        $response->assertJsonPath('categories.0.marker_override.icon', 'celebration');
        $response->assertJsonPath('categories.0.marker_override.color', '#FF2200');
        $response->assertJsonPath('categories.0.marker_override.icon_color', '#101010');
        $response->assertJsonPath('categories.1.key', 'praia');
        $response->assertJsonPath('categories.1.label', 'Praias');
        $response->assertJsonPath('categories.1.query.source', 'static_asset');
        $response->assertJsonPath('categories.1.query.types.0', 'beach_spot');
        $response->assertJsonPath('categories.1.override_marker', true);
        $response->assertJsonPath('categories.1.marker_override.mode', 'image');
        $overrideImageUri = (string) $response->json('categories.1.marker_override.image_uri');
        $this->assertSame('/api/v1/media/map-filters/praia', parse_url($overrideImageUri, PHP_URL_PATH));
    }

    public function test_map_filters_normalize_bson_marker_override_icon_color(): void
    {
        $location = $this->point(-40.0, -20.0);

        TenantSettings::query()->firstOrFail()->update([
            'map_ui' => [
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
                'filters' => [
                    new BSONDocument([
                        'key' => 'events',
                        'label' => 'Eventos',
                        'override_marker' => true,
                        'marker_override' => new BSONDocument([
                            'mode' => 'icon',
                            'icon' => 'music',
                            'color' => '#C6141F',
                            'icon_color' => '#101010',
                        ]),
                        'query' => new BSONDocument([
                            'source' => 'event',
                        ]),
                    ]),
                ],
            ],
        ]);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-visual',
            'ref_slug' => 'event-visual',
            'ref_path' => '/agenda/evento/event-visual',
            'name' => 'Event Visual',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 60,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/filters?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $response->assertJsonPath('categories.0.key', 'events');
        $response->assertJsonPath('categories.0.query.source', 'event');
        $response->assertJsonPath('categories.0.override_marker', true);
        $response->assertJsonPath('categories.0.marker_override.mode', 'icon');
        $response->assertJsonPath('categories.0.marker_override.icon', 'music');
        $response->assertJsonPath('categories.0.marker_override.color', '#C6141F');
        $response->assertJsonPath('categories.0.marker_override.icon_color', '#101010');
    }

    public function test_map_filters_normalize_bson_marker_override_image_uri(): void
    {
        $location = $this->point(-40.0, -20.0);

        TenantSettings::query()->firstOrFail()->update([
            'map_ui' => [
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
                'filters' => [
                    new BSONDocument([
                        'key' => 'praia',
                        'label' => 'Praias',
                        'override_marker' => true,
                        'marker_override' => new BSONDocument([
                            'mode' => 'image',
                            'image_uri' => 'https://tenant-zeta.test/map-filters/praia/image?v=1710000002',
                        ]),
                        'query' => new BSONDocument([
                            'source' => 'static_asset',
                            'types' => ['beach_spot'],
                        ]),
                    ]),
                ],
            ],
        ]);

        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-beach',
            'ref_slug' => 'praia-azul',
            'ref_path' => '/static/praia-azul',
            'name' => 'Praia Azul',
            'category' => 'beach',
            'source_type' => 'beach_spot',
            'location' => $location,
            'priority' => 40,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/filters?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $response->assertJsonPath('categories.0.key', 'praia');
        $response->assertJsonPath('categories.0.override_marker', true);
        $response->assertJsonPath('categories.0.marker_override.mode', 'image');
        $overrideImageUri = (string) $response->json('categories.0.marker_override.image_uri');
        $this->assertNotSame('', $overrideImageUri);
        $this->assertSame('/api/v1/media/map-filters/praia', parse_url($overrideImageUri, PHP_URL_PATH));
        parse_str((string) parse_url($overrideImageUri, PHP_URL_QUERY), $imageQuery);
        $this->assertSame('1710000002', $imageQuery['v'] ?? null);
    }

    public function test_map_filters_returns_configured_filters_even_when_count_is_zero(): void
    {
        $location = $this->point(-40.0, -20.0);

        TenantSettings::query()->firstOrFail()->update([
            'map_ui' => [
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
                'filters' => [
                    [
                        'key' => 'events',
                        'label' => 'Eventos',
                        'query' => [
                            'source' => 'event',
                        ],
                    ],
                    [
                        'key' => 'restaurantes',
                        'label' => 'Restaurantes',
                        'query' => [
                            'source' => 'account_profile',
                            'types' => ['restaurant'],
                        ],
                    ],
                ],
            ],
        ]);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-only',
            'ref_slug' => 'event-only',
            'ref_path' => '/agenda/evento/event-only',
            'name' => 'Event only',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 60,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/filters?ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $response->assertStatus(200);

        $response->assertJsonPath('categories.0.key', 'events');
        $response->assertJsonPath('categories.0.count', 1);
        $response->assertJsonPath('categories.1.key', 'restaurantes');
        $response->assertJsonPath('categories.1.count', 0);
    }

    public function test_map_pois_supports_source_and_types_filters(): void
    {
        $location = $this->point(-40.0, -20.0);

        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-show',
            'ref_slug' => 'event-show',
            'ref_path' => '/agenda/evento/event-show',
            'name' => 'Event Show',
            'category' => 'event',
            'source_type' => 'show',
            'location' => $location,
            'priority' => 80,
            'is_active' => true,
            'exact_key' => $this->exactKey($location),
        ]);
        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-festival',
            'ref_slug' => 'event-festival',
            'ref_path' => '/agenda/evento/event-festival',
            'name' => 'Event Festival',
            'category' => 'event',
            'source_type' => 'festival',
            'location' => $location,
            'priority' => 60,
            'is_active' => true,
            'exact_key' => '-20.00010,-40.00010',
        ]);
        MapPoi::create([
            'ref_type' => 'static',
            'ref_id' => 'static-poi',
            'ref_slug' => 'static-poi',
            'ref_path' => '/static/static-poi',
            'name' => 'Static POI',
            'category' => 'beach',
            'source_type' => 'beach_spot',
            'location' => $location,
            'priority' => 40,
            'is_active' => true,
            'exact_key' => '-20.00020,-40.00020',
        ]);

        $allEvents = $this->getJson("{$this->base_api_tenant}map/pois?source=event&ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $allEvents->assertStatus(200);
        $this->assertCount(2, $allEvents->json('stacks'));

        $showsOnly = $this->getJson("{$this->base_api_tenant}map/pois?source=event&types[]=show&ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $showsOnly->assertStatus(200);
        $this->assertCount(1, $showsOnly->json('stacks'));
        $showsOnly->assertJsonPath('stacks.0.top_poi.ref_id', 'event-show');

        $beachesOnly = $this->getJson("{$this->base_api_tenant}map/pois?source=static_asset&types[]=beach_spot&ne_lat=-19.0&ne_lng=-39.0&sw_lat=-21.0&sw_lng=-41.0");
        $beachesOnly->assertStatus(200);
        $this->assertCount(1, $beachesOnly->json('stacks'));
        $beachesOnly->assertJsonPath('stacks.0.top_poi.ref_id', 'static-poi');
    }

    public function test_map_pois_box_includes_polygon_discovery_scope_intersections(): void
    {
        MapPoi::create([
            'ref_type' => 'event',
            'ref_id' => 'event-polygon',
            'projection_key' => 'event:event-polygon',
            'source_checkpoint' => 223344,
            'ref_slug' => 'event-polygon',
            'ref_path' => '/agenda/evento/event-polygon',
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

    public function test_static_asset_creation_projects_map_poi(): void
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
            'email' => uniqid('map-user', true).'@example.org',
            'password' => 'Secret!234',
            'timezone' => 'America/Sao_Paulo',
        ], (string) $role->_id);
    }

    /**
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

        return $lat.','.$lng;
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
