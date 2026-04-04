<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AttendanceCommitment;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AgendaAndEventsControllerTest extends TestCaseTenant
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

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        Event::query()->delete();
        EventOccurrence::query()->delete();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['account-users:view']);

        Sanctum::actingAs($this->user, ['account-users:view']);

        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'radius' => [
                    'min_km' => 1,
                    'default_km' => 5,
                    'max_km' => 50,
                ],
                'default_origin' => [
                    'lat' => -20.671339,
                    'lng' => -40.495395,
                    'label' => 'Praia do Morro',
                ],
            ],
        ]);
    }

    public function test_agenda_default_returns_upcoming_and_now(): void
    {
        $now = Carbon::now();

        $this->createEvent([
            'title' => 'Upcoming Event',
            'date_time_start' => $now->copy()->addDays(2),
            'date_time_end' => $now->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Happening Now',
            'date_time_start' => $now->copy()->subHour(),
            'date_time_end' => $now->copy()->addHour(),
        ]);

        $this->createEvent([
            'title' => 'Past Event',
            'date_time_start' => $now->copy()->subDays(2),
            'date_time_end' => $now->copy()->subDays(2)->addHours(2),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(200);
        $items = $response->json('items');

        $this->assertCount(2, $items);
        $titles = array_map(static fn ($item): string => (string) ($item['title'] ?? ''), $items);
        $this->assertContains('Upcoming Event', $titles);
        $this->assertContains('Happening Now', $titles);
    }

    public function test_agenda_default_includes_live_now_and_excludes_past_events(): void
    {
        $now = Carbon::now();

        $liveNow = $this->createEvent([
            'title' => 'Live Agora',
            'date_time_start' => $now->copy()->subMinutes(30),
            'date_time_end' => $now->copy()->addMinutes(30),
        ]);
        $upcoming = $this->createEvent([
            'title' => 'Upcoming Visible',
            'date_time_start' => $now->copy()->addDays(1),
            'date_time_end' => $now->copy()->addDays(1)->addHours(2),
        ]);
        $this->createEvent([
            'title' => 'Past Hidden',
            'date_time_start' => $now->copy()->subDays(1)->subHours(3),
            'date_time_end' => $now->copy()->subDays(1),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");
        $response->assertStatus(200);

        $items = $response->json('items');
        $titles = array_map(static fn ($item): string => (string) ($item['title'] ?? ''), $items);

        $this->assertContains('Live Agora', $titles);
        $this->assertContains('Upcoming Visible', $titles);
        $this->assertNotContains('Past Hidden', $titles);

        $liveItem = collect($items)->firstWhere('title', 'Live Agora');
        $upcomingItem = collect($items)->firstWhere('title', 'Upcoming Visible');

        $this->assertNotNull($liveItem);
        $this->assertNotNull($upcomingItem);
        $this->assertSame((string) $liveNow->_id, (string) ($liveItem['event_id'] ?? null));
        $this->assertSame((string) $upcoming->_id, (string) ($upcomingItem['event_id'] ?? null));
        $this->assertNotSame('', (string) ($liveItem['occurrence_id'] ?? ''));
        $this->assertNotSame('', (string) ($upcomingItem['occurrence_id'] ?? ''));
    }

    public function test_agenda_live_now_only_returns_only_current_occurrences_with_artists(): void
    {
        $now = Carbon::now();

        $this->createEvent([
            'title' => 'Live Discovery Slot',
            'date_time_start' => $now->copy()->subMinutes(15),
            'date_time_end' => $now->copy()->addMinutes(45),
            'artists' => [
                [
                    'id' => 'artist-live-1',
                    'display_name' => 'Live Artist One',
                    'avatar_url' => 'https://example.org/artist-live-1.jpg',
                    'highlight' => true,
                    'genres' => ['samba'],
                ],
                [
                    'id' => 'artist-live-2',
                    'display_name' => 'Live Artist Two',
                    'avatar_url' => null,
                    'highlight' => false,
                    'genres' => ['mpb'],
                ],
            ],
        ]);

        $this->createEvent([
            'title' => 'Upcoming Hidden In Live',
            'date_time_start' => $now->copy()->addHours(2),
            'date_time_end' => $now->copy()->addHours(4),
        ]);

        $this->createEvent([
            'title' => 'Past Hidden In Live',
            'date_time_start' => $now->copy()->subHours(4),
            'date_time_end' => $now->copy()->subHours(2),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?live_now_only=1&page=1&page_size=10");
        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Live Discovery Slot', (string) ($items[0]['title'] ?? ''));
        $this->assertSame('Live Artist One', (string) ($items[0]['artists'][0]['display_name'] ?? ''));
        $this->assertSame('artist-live-1', (string) ($items[0]['artists'][0]['id'] ?? ''));
    }

    public function test_agenda_public_endpoint_shows_only_effectively_published_items(): void
    {
        $now = Carbon::now();

        $this->createEvent([
            'title' => 'Published Visible',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'date_time_start' => $now->copy()->addDays(2),
            'date_time_end' => $now->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Draft Hidden',
            'publication' => [
                'status' => 'draft',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'date_time_start' => $now->copy()->addDays(2),
            'date_time_end' => $now->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Scheduled Hidden',
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => $now->copy()->addHour(),
            ],
            'date_time_start' => $now->copy()->addDays(2),
            'date_time_end' => $now->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Ended Hidden',
            'publication' => [
                'status' => 'ended',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->addDays(2),
            'date_time_end' => $now->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Published Future Hidden',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->addHour(),
            ],
            'date_time_start' => $now->copy()->addDays(2),
            'date_time_end' => $now->copy()->addDays(2)->addHours(2),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=20");
        $response->assertStatus(200);

        $items = $response->json('items');
        $titles = array_map(static fn ($item): string => (string) ($item['title'] ?? ''), $items);

        $this->assertSame(['Published Visible'], $titles);
    }

    public function test_agenda_past_only_returns_past_not_ongoing(): void
    {
        $now = Carbon::now();

        $this->createEvent([
            'title' => 'Past Event',
            'date_time_start' => $now->copy()->subDays(2),
            'date_time_end' => $now->copy()->subDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Ongoing Event',
            'date_time_start' => $now->copy()->subHour(),
            'date_time_end' => $now->copy()->addHour(),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?past_only=1&page=1&page_size=10");

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Past Event', $items[0]['title']);
    }

    public function test_agenda_filters_by_typed_taxonomy_terms(): void
    {
        $this->createEvent([
            'title' => 'Event Taxonomy Match',
            'taxonomy_terms' => [
                ['type' => 'mood', 'value' => 'sunset'],
            ],
        ]);

        $this->createEvent([
            'title' => 'Venue Taxonomy Match',
            'venue' => [
                'id' => 'venue-2',
                'display_name' => 'Casa Noturna',
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'seafood'],
                ],
            ],
        ]);

        $this->createEvent([
            'title' => 'Artist Taxonomy Match',
            'artists' => [
                [
                    'id' => 'artist-3',
                    'display_name' => 'DJ Mar',
                    'avatar_url' => null,
                    'highlight' => false,
                    'genres' => ['house'],
                    'taxonomy_terms' => [
                        ['type' => 'music_genre', 'value' => 'samba'],
                    ],
                ],
            ],
        ]);

        $this->createEvent([
            'title' => 'No Taxonomy Match',
            'taxonomy_terms' => [
                ['type' => 'mood', 'value' => 'night'],
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?taxonomy[0][type]=mood&taxonomy[0][value]=sunset&page=1&page_size=10"
        );
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('Event Taxonomy Match', $response->json('items.0.title'));

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?taxonomy[0][type]=cuisine&taxonomy[0][value]=seafood&page=1&page_size=10"
        );
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('Venue Taxonomy Match', $response->json('items.0.title'));

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?taxonomy[0][type]=music_genre&taxonomy[0][value]=samba&page=1&page_size=10"
        );
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('Artist Taxonomy Match', $response->json('items.0.title'));
    }

    public function test_agenda_supports_text_search_query_param(): void
    {
        $this->createEvent([
            'title' => 'Solar Sunset Party',
            'date_time_start' => Carbon::now()->addDay(),
            'date_time_end' => Carbon::now()->addDay()->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'No Match Agenda',
            'date_time_start' => Carbon::now()->addDay(),
            'date_time_end' => Carbon::now()->addDay()->addHours(2),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?search=Solar&page=1&page_size=10");
        $response->assertStatus(200);

        $items = $response->json('items');
        $titles = array_map(static fn ($item): string => (string) ($item['title'] ?? ''), $items);
        $this->assertContains('Solar Sunset Party', $titles);
        $this->assertNotContains('No Match Agenda', $titles);

        $partialResponse = $this->getJson("{$this->base_api_tenant}agenda?search=Sola&page=1&page_size=10");
        $partialResponse->assertStatus(200);
        $partialItems = $partialResponse->json('items');
        $partialTitles = array_map(static fn ($item): string => (string) ($item['title'] ?? ''), $partialItems);
        $this->assertContains('Solar Sunset Party', $partialTitles);
        $this->assertNotContains('No Match Agenda', $partialTitles);

        $containsResponse = $this->getJson("{$this->base_api_tenant}agenda?search=olar&page=1&page_size=10");
        $containsResponse->assertStatus(200);
        $containsItems = $containsResponse->json('items');
        $containsTitles = array_map(static fn ($item): string => (string) ($item['title'] ?? ''), $containsItems);
        $this->assertContains('Solar Sunset Party', $containsTitles);
        $this->assertNotContains('No Match Agenda', $containsTitles);
    }

    public function test_agenda_rejects_search_combined_with_geo_filters(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?search=solar&origin_lat=-20.0&origin_lng=-40.0&max_distance_meters=5000&page=1&page_size=10"
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['search']);
    }

    public function test_event_stream_rejects_search_combined_with_geo_filters(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}events/stream?search=solar&origin_lat=-20.0&origin_lng=-40.0&max_distance_meters=5000",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['search']);
    }

    public function test_agenda_geo_filters_exclude_events_outside_distance(): void
    {
        $this->createEvent([
            'title' => 'Remote Event',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [100.0, 40.0],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [100.0, 40.0],
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?origin_lat=0&origin_lng=0&max_distance_meters=10&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(0, $items);
    }

    public function test_agenda_returns_only_eligible_occurrences_from_mixed_dataset(): void
    {
        $now = Carbon::now();

        $eligibleOne = $this->createEvent([
            'title' => 'Eligible One',
            'date_time_start' => $now->copy()->addDays(10),
            'date_time_end' => $now->copy()->addDays(10)->addHours(2),
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.4950, -20.6710],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.4950, -20.6710],
            ],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => 'account-profile-1',
                'metadata' => [
                    'display_name' => 'Eligible Host One',
                ],
            ],
        ]);

        $eligibleTwo = $this->createEvent([
            'title' => 'Eligible Two',
            'date_time_start' => $now->copy()->addDays(11),
            'date_time_end' => $now->copy()->addDays(11)->addHours(2),
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.4700, -20.6400],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.4700, -20.6400],
            ],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => 'account-profile-2',
                'metadata' => [
                    'display_name' => 'Eligible Host Two',
                ],
            ],
        ]);

        $draftHidden = $this->createEvent([
            'title' => 'Draft Hidden',
            'publication' => [
                'status' => 'draft',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'date_time_start' => $now->copy()->addDays(12),
            'date_time_end' => $now->copy()->addDays(12)->addHours(2),
        ]);

        $pastHidden = $this->createEvent([
            'title' => 'Past Hidden',
            'date_time_start' => $now->copy()->subDays(2),
            'date_time_end' => $now->copy()->subDays(2)->addHours(2),
        ]);

        $deletedOccurrenceHidden = $this->createEvent([
            'title' => 'Deleted Occurrence Hidden',
            'date_time_start' => $now->copy()->addDays(13),
            'date_time_end' => $now->copy()->addDays(13)->addHours(2),
        ]);
        EventOccurrence::query()
            ->where('event_id', (string) $deletedOccurrenceHidden->_id)
            ->update([
                'deleted_at' => $now->copy(),
            ]);

        $outOfRadiusHidden = $this->createEvent([
            'title' => 'Out Of Radius Hidden',
            'date_time_start' => $now->copy()->addDays(14),
            'date_time_end' => $now->copy()->addDays(14)->addHours(2),
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [100.0, 40.0],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [100.0, 40.0],
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?origin_lat=-20.671339&origin_lng=-40.495395&max_distance_meters=50000&page=1&page_size=20"
        );

        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(2, $items);

        $eventIds = array_map(static fn ($item): string => (string) ($item['event_id'] ?? ''), $items);
        $this->assertContains((string) $eligibleOne->_id, $eventIds);
        $this->assertContains((string) $eligibleTwo->_id, $eventIds);
        $this->assertNotContains((string) $draftHidden->_id, $eventIds);
        $this->assertNotContains((string) $pastHidden->_id, $eventIds);
        $this->assertNotContains((string) $deletedOccurrenceHidden->_id, $eventIds);
        $this->assertNotContains((string) $outOfRadiusHidden->_id, $eventIds);
    }

    public function test_agenda_geo_query_fails_when_geo_index_is_missing(): void
    {
        $this->createEvent([
            'title' => 'Indexed Geo Event',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.495395, -20.671339],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.495395, -20.671339],
            ],
        ]);

        $collection = DB::connection('tenant')->getDatabase()->selectCollection('event_occurrences');
        $collection->dropIndexes();

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?origin_lat=-20.671339&origin_lng=-40.495395&max_distance_meters=5000&page=1&page_size=10"
        );

        $response->assertStatus(500);

        $indexNames = [];
        foreach ($collection->listIndexes() as $index) {
            $indexNames[] = (string) $index->getName();
        }
        $this->assertNotContains('geo_location_2dsphere', $indexNames);

        // Keep test isolation: recreate the required geo index for subsequent tests.
        $collection->createIndex(['geo_location' => '2dsphere']);
    }

    public function test_agenda_confirmed_only_returns_only_confirmed_events(): void
    {
        $confirmed = $this->createEvent([
            'title' => 'Confirmed Visible',
            'date_time_start' => Carbon::now()->addDay(),
            'date_time_end' => Carbon::now()->addDay()->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Not Confirmed Hidden',
            'date_time_start' => Carbon::now()->addDays(2),
            'date_time_end' => Carbon::now()->addDays(2)->addHours(2),
        ]);

        $this->createActiveAttendanceCommitment((string) $confirmed->_id);

        $response = $this->getJson("{$this->base_api_tenant}agenda?confirmed_only=1&page=1&page_size=10");
        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Confirmed Visible', (string) ($items[0]['title'] ?? ''));
        $this->assertSame((string) $confirmed->_id, (string) ($items[0]['event_id'] ?? ''));
    }

    public function test_agenda_confirmed_only_returns_empty_when_user_has_no_confirmed_events(): void
    {
        $this->createEvent([
            'title' => 'Upcoming Event',
            'date_time_start' => Carbon::now()->addDay(),
            'date_time_end' => Carbon::now()->addDay()->addHours(2),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?confirmed_only=1&page=1&page_size=10");
        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $this->assertSame([], $response->json('items'));
    }

    public function test_agenda_confirmed_only_ignores_geo_distance_filtering(): void
    {
        $confirmed = $this->createEvent([
            'title' => 'Confirmed Far Away',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [120.0, 45.0],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [120.0, 45.0],
            ],
        ]);

        $this->createActiveAttendanceCommitment((string) $confirmed->_id);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?confirmed_only=1&origin_lat=0&origin_lng=0&max_distance_meters=1&page=1&page_size=10"
        );
        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((string) $confirmed->_id, (string) ($items[0]['event_id'] ?? ''));
    }

    public function test_event_detail_resolves_slug_and_id(): void
    {
        $event = $this->createEvent([
            'title' => 'Slug Test Event',
        ]);

        $hexSlug = 'abcdef123456abcdef123456';
        $event->slug = $hexSlug;
        $event->save();

        $response = $this->getJson("{$this->base_api_tenant}events/{$hexSlug}");
        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', $hexSlug);

        $response = $this->getJson("{$this->base_api_tenant}events/{$event->_id}");
        $response->assertStatus(200);
        $response->assertJsonPath('data.event_id', (string) $event->_id);
    }

    public function test_event_detail_returns404_when_missing(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}events/missing-event");
        $response->assertStatus(404);
    }

    public function test_event_detail_exposes_linked_account_profiles_for_dynamic_category_tabs(): void
    {
        $event = $this->createEvent([
            'venue' => [
                'id' => 'venue-1',
                'display_name' => 'Carvoeiro',
                'slug' => 'carvoeiro',
                'profile_type' => 'restaurant',
                'tagline' => 'Tag',
                'hero_image_url' => 'https://example.org/venue-cover.jpg',
                'logo_url' => 'https://example.org/venue-avatar.jpg',
                'avatar_url' => 'https://example.org/venue-avatar.jpg',
                'cover_url' => 'https://example.org/venue-cover.jpg',
                'taxonomy_terms' => [
                    ['type' => 'event_style', 'value' => 'showcase', 'name' => 'Showcase'],
                ],
            ],
            'artists' => [
                [
                    'id' => 'artist-1',
                    'display_name' => 'Ananda Torres',
                    'slug' => 'ananda-torres',
                    'profile_type' => 'artist',
                    'avatar_url' => 'https://example.org/artist-avatar.jpg',
                    'cover_url' => 'https://example.org/artist-cover.jpg',
                    'highlight' => false,
                    'genres' => ['samba'],
                    'taxonomy_terms' => [
                        ['type' => 'event_style', 'value' => 'showcase', 'name' => 'Showcase'],
                    ],
                ],
            ],
            'event_parties' => [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => 'artist-1',
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => 'Ananda Torres',
                        'slug' => 'ananda-torres',
                        'profile_type' => 'artist',
                        'avatar_url' => 'https://example.org/artist-avatar.jpg',
                        'cover_url' => 'https://example.org/artist-cover.jpg',
                        'taxonomy_terms' => [
                            ['type' => 'event_style', 'value' => 'showcase', 'name' => 'Showcase'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("{$this->base_api_tenant}events/{$event->_id}");
        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.linked_account_profiles');
        $response->assertJsonPath('data.linked_account_profiles.0.id', 'artist-1');
        $response->assertJsonPath('data.linked_account_profiles.0.profile_type', 'artist');
        $response->assertJsonPath('data.linked_account_profiles.0.slug', 'ananda-torres');
        $response->assertJsonPath(
            'data.linked_account_profiles.0.taxonomy_terms.0.name',
            'Showcase'
        );
    }

    public function test_event_stream_returns_deltas(): void
    {
        $event = $this->createEvent(['title' => 'Stream Event']);
        $occurrence = EventOccurrence::query()->where('event_id', (string) $event->_id)->first();
        $this->assertNotNull($occurrence);
        $since = Carbon::now()->subMinute()->toISOString();

        $response = $this->get(
            "{$this->base_api_tenant}events/stream",
            [
                'Last-Event-ID' => $since,
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('occurrence.created', $content);
        $this->assertStringContainsString((string) $event->_id, $content);
        $this->assertStringContainsString((string) $occurrence->_id, $content);
    }

    public function test_event_stream_reconnect_uses_last_event_id_without_replay(): void
    {
        $this->createEvent(['title' => 'Reconnect Event']);

        $initialResponse = $this->get(
            "{$this->base_api_tenant}events/stream",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
                'Accept' => 'text/event-stream',
            ]
        );

        $initialResponse->assertStatus(200);
        $initialContent = $initialResponse->streamedContent();
        $this->assertStringContainsString('event:', $initialContent);

        $matched = preg_match_all('/^id:\\s*(.+)$/m', $initialContent, $cursorMatches);
        $this->assertGreaterThan(0, $matched);
        $cursor = trim((string) ($cursorMatches[1][count($cursorMatches[1]) - 1] ?? ''));
        $this->assertNotSame('', $cursor);
        $cursor = Carbon::parse($cursor)->addSecond()->toISOString();

        $reconnectResponse = $this->get(
            "{$this->base_api_tenant}events/stream",
            [
                'Last-Event-ID' => $cursor,
                'Accept' => 'text/event-stream',
            ]
        );

        $reconnectResponse->assertStatus(200);
        $this->assertStringNotContainsString('event:', $reconnectResponse->streamedContent());
    }

    public function test_event_stream_returns_empty_payload_for_invalid_last_event_id(): void
    {
        $this->createEvent(['title' => 'Invalid Cursor Event']);

        $response = $this->get(
            "{$this->base_api_tenant}events/stream",
            [
                'Last-Event-ID' => 'not-a-valid-date',
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertStatus(200);
        $this->assertStringNotContainsString('event:', $response->streamedContent());
    }

    public function test_event_stream_returns_deleted_delta_for_future_scheduled_publication(): void
    {
        $event = $this->createEvent([
            'title' => 'Future Scheduled Event',
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => Carbon::now()->addDay(),
            ],
        ]);

        $response = $this->get(
            "{$this->base_api_tenant}events/stream",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString('occurrence.deleted', $content);
        $this->assertStringContainsString((string) $event->_id, $content);
    }

    public function test_event_stream_confirmed_only_returns_only_confirmed_event_deltas(): void
    {
        $confirmed = $this->createEvent(['title' => 'Confirmed Stream Event']);
        $other = $this->createEvent(['title' => 'Other Stream Event']);

        $this->createActiveAttendanceCommitment((string) $confirmed->_id);

        $response = $this->get(
            "{$this->base_api_tenant}events/stream?confirmed_only=1",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString((string) $confirmed->_id, $content);
        $this->assertStringNotContainsString((string) $other->_id, $content);
    }

    public function test_agenda_requires_auth(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $response = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");
        $response->assertStatus(401);
    }

    public function test_agenda_validates_origin_pairs(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}agenda?origin_lat=10&page=1&page_size=10");
        $response->assertStatus(422);
    }

    public function test_agenda_rejects_live_now_only_combined_with_past_only(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}agenda?live_now_only=1&past_only=1&page=1&page_size=10");
        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.live_now_only'));
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Test Role',
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Test User',
            'email' => uniqid('event-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createEvent(array $overrides = []): Event
    {
        $now = Carbon::now();

        $event = Event::create(array_merge([
            'title' => 'Test Event',
            'content' => 'Event content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'place_ref' => [
                'type' => 'venue',
                'id' => 'venue-1',
                'metadata' => [
                    'display_name' => 'Venue Name',
                ],
            ],
            'type' => [
                'id' => 'type-1',
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Show desc',
                'icon' => null,
                'color' => null,
            ],
            'venue' => [
                'id' => 'venue-1',
                'display_name' => 'Venue Name',
                'tagline' => 'Tag',
                'hero_image_url' => null,
                'logo_url' => null,
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'italian'],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => 'https://example.org/thumb.jpg',
                ],
            ],
            'date_time_start' => $now->copy()->addDay(),
            'date_time_end' => $now->copy()->addDay()->addHours(2),
            'artists' => [
                [
                    'id' => 'artist-1',
                    'display_name' => 'Artist One',
                    'avatar_url' => null,
                    'highlight' => false,
                    'genres' => ['rock'],
                    'taxonomy_terms' => [
                        ['type' => 'music_genre', 'value' => 'rock'],
                    ],
                ],
            ],
            'tags' => ['music'],
            'categories' => ['culture'],
            'taxonomy_terms' => [],
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'is_active' => true,
        ], $overrides));

        $occurrences = [[
            'date_time_start' => Carbon::instance($event->date_time_start),
            'date_time_end' => $event->date_time_end ? Carbon::instance($event->date_time_end) : null,
        ]];

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, $occurrences);

        return $event->fresh();
    }

    private function createActiveAttendanceCommitment(string $eventId, ?string $occurrenceId = null): void
    {
        AttendanceCommitment::create([
            'user_id' => (string) $this->user->getAuthIdentifier(),
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'kind' => 'free_confirmation',
            'status' => 'active',
            'source' => 'direct',
            'confirmed_at' => Carbon::now(),
            'canceled_at' => null,
        ]);
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

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
