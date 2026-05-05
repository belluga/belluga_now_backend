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
use App\Models\Tenants\EventType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
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

        Event::withTrashed()->forceDelete();
        EventOccurrence::withTrashed()->forceDelete();

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
            'artists' => [],
            'event_parties' => [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => 'artist-live-1',
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => 'Live Artist One',
                        'slug' => 'live-artist-one',
                        'profile_type' => 'artist',
                        'avatar_url' => 'https://example.org/artist-live-1.jpg',
                        'cover_url' => null,
                        'genres' => ['samba'],
                        'taxonomy_terms' => [],
                    ],
                ],
                [
                    'party_type' => 'band',
                    'party_ref_id' => 'artist-live-2',
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => 'Live Artist Two',
                        'slug' => 'live-artist-two',
                        'profile_type' => 'band',
                        'avatar_url' => null,
                        'cover_url' => null,
                        'genres' => ['mpb'],
                        'taxonomy_terms' => [],
                    ],
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

    public function test_agenda_filters_by_effective_event_taxonomy_terms(): void
    {
        $this->createEvent([
            'title' => 'Event Taxonomy Match',
            'taxonomy_terms' => [
                ['type' => 'mood', 'value' => 'sunset'],
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
    }

    public function test_agenda_taxonomy_filter_uses_effective_occurrence_taxonomy_overrides(): void
    {
        $sportTaxonomy = Taxonomy::query()->create([
            'slug' => 'sport_kind',
            'name' => 'Sport Kind',
            'applies_to' => ['event'],
        ]);
        TaxonomyTerm::query()->create([
            'taxonomy_id' => (string) $sportTaxonomy->_id,
            'slug' => 'futebol',
            'name' => 'Futebol',
        ]);
        TaxonomyTerm::query()->create([
            'taxonomy_id' => (string) $sportTaxonomy->_id,
            'slug' => 'handebol',
            'name' => 'Handebol',
        ]);

        $eventType = EventType::query()->create([
            'name' => 'Synthetic Sports',
            'slug' => 'synthetic-sports',
            'allowed_taxonomies' => ['sport_kind'],
        ]);

        $event = $this->createEvent([
            'title' => 'Synthetic multi occurrence sports event',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Synthetic Sports',
                'slug' => 'synthetic-sports',
                'allowed_taxonomies' => ['sport_kind'],
            ],
            'taxonomy_terms' => [
                ['type' => 'sport_kind', 'value' => 'futebol'],
            ],
        ]);

        $now = Carbon::now()->addDay();
        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [
            [
                'date_time_start' => $now->copy()->setHour(10),
                'date_time_end' => $now->copy()->setHour(12),
                'taxonomy_terms' => [
                    ['type' => 'sport_kind', 'value' => 'futebol'],
                ],
            ],
            [
                'date_time_start' => $now->copy()->addDay()->setHour(10),
                'date_time_end' => $now->copy()->addDay()->setHour(12),
                'taxonomy_terms' => [
                    ['type' => 'sport_kind', 'value' => 'handebol'],
                ],
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?taxonomy[0][type]=sport_kind&taxonomy[0][value]=futebol&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('futebol', $items[0]['taxonomy_terms'][0]['value'] ?? null);
        $event = Event::query()->findOrFail($items[0]['event_id'] ?? '');
        $this->assertSame(
            (string) data_get($event->occurrence_refs, '0.occurrence_id'),
            (string) ($items[0]['occurrence_id'] ?? '')
        );
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

    public function test_agenda_rejects_out_of_range_geo_coordinates(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?origin_lat=91&origin_lng=181&max_distance_meters=5000&page=1&page_size=10"
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['origin_lat', 'origin_lng']);
    }

    public function test_event_stream_rejects_out_of_range_geo_coordinates(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}events/stream?origin_lat=-91&origin_lng=-181&max_distance_meters=5000",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['origin_lat', 'origin_lng']);
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

        $this->createActiveAttendanceCommitment(
            (string) $confirmed->_id,
            $this->firstOccurrenceId($confirmed),
        );

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

    public function test_agenda_filters_by_occurrence_ids_without_walking_unrelated_events(): void
    {
        $target = $this->createEvent([
            'title' => 'Pending Invite Target',
            'date_time_start' => Carbon::now()->addDays(3),
            'date_time_end' => Carbon::now()->addDays(3)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Unrelated Agenda Item',
            'date_time_start' => Carbon::now()->addDay(),
            'date_time_end' => Carbon::now()->addDay()->addHours(2),
        ]);

        $targetOccurrenceId = $this->firstOccurrenceId($target);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?occurrence_ids[]={$targetOccurrenceId}&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Pending Invite Target', (string) ($items[0]['title'] ?? ''));
        $this->assertSame((string) $target->_id, (string) ($items[0]['event_id'] ?? ''));
        $this->assertSame($targetOccurrenceId, (string) ($items[0]['occurrence_id'] ?? ''));
    }

    public function test_agenda_filters_by_occurrence_ids_with_geo_parameters(): void
    {
        $target = $this->createEvent([
            'title' => 'Pending Invite Geo Target',
            'date_time_start' => Carbon::now()->addDays(3),
            'date_time_end' => Carbon::now()->addDays(3)->addHours(2),
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

        $this->createEvent([
            'title' => 'Pending Invite Geo Unrelated',
            'date_time_start' => Carbon::now()->addDays(4),
            'date_time_end' => Carbon::now()->addDays(4)->addHours(2),
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
        ]);

        $targetOccurrenceId = $this->firstOccurrenceId($target);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?occurrence_ids[]={$targetOccurrenceId}&origin_lat=-20.671339&origin_lng=-40.495395&max_distance_meters=5000&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Pending Invite Geo Target', (string) ($items[0]['title'] ?? ''));
        $this->assertSame($targetOccurrenceId, (string) ($items[0]['occurrence_id'] ?? ''));
    }

    public function test_agenda_filters_by_occurrence_ids_with_search_parameters(): void
    {
        $target = $this->createEvent([
            'title' => 'Solar Pending Invite Target',
            'date_time_start' => Carbon::now()->addDays(3),
            'date_time_end' => Carbon::now()->addDays(3)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Solar Pending Invite Unrelated',
            'date_time_start' => Carbon::now()->addDays(4),
            'date_time_end' => Carbon::now()->addDays(4)->addHours(2),
        ]);

        $targetOccurrenceId = $this->firstOccurrenceId($target);

        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?search=Solar&occurrence_ids[]={$targetOccurrenceId}&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('Solar Pending Invite Target', (string) ($items[0]['title'] ?? ''));
        $this->assertSame($targetOccurrenceId, (string) ($items[0]['occurrence_id'] ?? ''));
    }

    public function test_occurrence_ids_are_applied_in_initial_agenda_and_stream_pipeline_stages(): void
    {
        $occurrenceId = '507f1f77bcf86cd799439011';
        $baseFilters = [
            'categories' => [],
            'tags' => [],
            'taxonomy' => [],
            'occurrence_ids' => [$occurrenceId],
            'search' => null,
            'past_only' => false,
            'live_now_only' => false,
            'confirmed_only' => false,
            'origin_lat' => -20.671339,
            'origin_lng' => -40.495395,
            'max_distance_meters' => 5000.0,
            'use_geo' => true,
        ];

        $geoAgendaPipeline = $this->buildAgendaPipelineForTest($baseFilters, true);
        $this->assertTrue(
            $this->matchExpressionContainsDocumentId(
                $geoAgendaPipeline[0]['$geoNear']['query'] ?? [],
                $occurrenceId
            )
        );

        $searchAgendaPipeline = $this->buildAgendaPipelineForTest([
            ...$baseFilters,
            'search' => 'Solar',
            'origin_lat' => null,
            'origin_lng' => null,
            'max_distance_meters' => null,
            'use_geo' => false,
        ], false);
        $this->assertTrue(
            $this->matchExpressionContainsDocumentId(
                $searchAgendaPipeline[0]['$match'] ?? [],
                $occurrenceId
            )
        );

        $geoStreamPipeline = $this->buildStreamPipelineForTest($baseFilters, true);
        $this->assertTrue(
            $this->matchExpressionContainsDocumentId(
                $geoStreamPipeline[0]['$geoNear']['query'] ?? [],
                $occurrenceId
            )
        );

        $streamPipeline = $this->buildStreamPipelineForTest([
            ...$baseFilters,
            'origin_lat' => null,
            'origin_lng' => null,
            'max_distance_meters' => null,
            'use_geo' => false,
        ], false);
        $this->assertTrue(
            $this->matchExpressionContainsDocumentId(
                $streamPipeline[0]['$match'] ?? [],
                $occurrenceId
            )
        );
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

        $this->createActiveAttendanceCommitment(
            (string) $confirmed->_id,
            $this->firstOccurrenceId($confirmed),
        );

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
            'artists' => [],
            'event_parties' => [
                [
                    'party_type' => 'band',
                    'party_ref_id' => 'artist-1',
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => 'Ananda Torres',
                        'slug' => 'ananda-torres',
                        'profile_type' => 'band',
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
        $response->assertJsonPath('data.linked_account_profiles.0.profile_type', 'band');
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

    public function test_event_stream_caps_stale_cursor_delta_replay(): void
    {
        foreach (range(1, InputConstraints::PUBLIC_STREAM_DELTA_LIMIT + 1) as $index) {
            $this->createEvent([
                'title' => sprintf('Stream Replay Cap Event %03d', $index),
                'date_time_start' => Carbon::now()->addDays(2)->addMinutes($index),
                'date_time_end' => Carbon::now()->addDays(2)->addMinutes($index + 30),
            ]);
        }

        $response = $this->get(
            "{$this->base_api_tenant}events/stream",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertStatus(200);
        $matched = preg_match_all('/^event:\\s*/m', $response->streamedContent());

        $this->assertSame(InputConstraints::PUBLIC_STREAM_DELTA_LIMIT, $matched);
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

        $this->createActiveAttendanceCommitment(
            (string) $confirmed->_id,
            $this->firstOccurrenceId($confirmed),
        );

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

    public function test_event_stream_filters_by_occurrence_ids_without_geo(): void
    {
        $target = $this->createEvent(['title' => 'Target Stream Occurrence']);
        $other = $this->createEvent(['title' => 'Other Stream Occurrence']);
        $targetOccurrenceId = $this->firstOccurrenceId($target);

        $response = $this->get(
            "{$this->base_api_tenant}events/stream?occurrence_ids[]={$targetOccurrenceId}",
            [
                'Last-Event-ID' => Carbon::now()->subMinute()->toISOString(),
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertStatus(200);
        $content = $response->streamedContent();

        $this->assertStringContainsString((string) $target->_id, $content);
        $this->assertStringContainsString($targetOccurrenceId, $content);
        $this->assertStringNotContainsString((string) $other->_id, $content);
        $this->assertStringNotContainsString($this->firstOccurrenceId($other), $content);
    }

    public function test_agenda_requires_auth(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $response = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");
        $response->assertStatus(401);
    }

    public function test_agenda_rejects_page_size_above_safe_maximum(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?page=1&page_size=".(InputConstraints::PUBLIC_PAGE_SIZE_MAX + 1)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page_size']);
    }

    public function test_agenda_rejects_page_above_safe_public_depth(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?page=".(InputConstraints::PUBLIC_PAGE_MAX + 1).'&page_size=10'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page']);
    }

    public function test_agenda_rejects_unbounded_public_filter_lists(): void
    {
        $query = http_build_query([
            'page' => 1,
            'page_size' => 10,
            'categories' => array_fill(0, InputConstraints::PUBLIC_FILTER_LIST_VALUES_MAX + 1, 'culture'),
            'tags' => ['music'],
            'taxonomy' => [[
                'type' => 'mood',
                'value' => 'sunset',
            ]],
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?{$query}");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['categories']);
    }

    public function test_agenda_rejects_unbounded_public_taxonomy_filter_work(): void
    {
        $taxonomy = array_map(
            static fn (int $index): array => [
                'type' => 'mood',
                'value' => "value-{$index}",
            ],
            range(1, InputConstraints::PUBLIC_FILTER_LIST_VALUES_MAX + 1)
        );
        $query = http_build_query([
            'page' => 1,
            'page_size' => 10,
            'taxonomy' => $taxonomy,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?{$query}");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['taxonomy']);
    }

    public function test_agenda_rejects_unbounded_public_geo_radius(): void
    {
        $response = $this->getJson(
            "{$this->base_api_tenant}agenda?origin_lat=-20&origin_lng=-40&max_distance_meters=".(InputConstraints::PUBLIC_GEO_DISTANCE_MAX_METERS + 1).'&page=1&page_size=10'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['max_distance_meters']);
    }

    public function test_event_query_service_clamps_public_page_size_when_called_directly(): void
    {
        foreach (range(1, InputConstraints::PUBLIC_PAGE_SIZE_MAX + 5) as $index) {
            $this->createEvent([
                'title' => sprintf('Bounded Agenda Event %03d', $index),
                'date_time_start' => Carbon::now()->addDay()->addMinutes($index),
                'date_time_end' => Carbon::now()->addDay()->addMinutes($index + 30),
            ]);
        }

        $payload = app(EventQueryService::class)->fetchAgenda([
            'page' => 1,
            'page_size' => InputConstraints::PUBLIC_PAGE_SIZE_MAX + 100,
        ], (string) $this->user->getAuthIdentifier());

        $this->assertCount(InputConstraints::PUBLIC_PAGE_SIZE_MAX, $payload['items']);
        $this->assertTrue((bool) $payload['has_more']);
    }

    public function test_event_query_service_clamps_public_page_depth_when_called_directly(): void
    {
        $paginator = app(EventQueryService::class)->paginateManagement(
            ['page' => InputConstraints::PUBLIC_PAGE_MAX + 100],
            false,
            1,
            false
        );

        $this->assertSame(InputConstraints::PUBLIC_PAGE_MAX, $paginator->currentPage());
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
            'tags' => ['music'],
            'categories' => ['culture'],
            'taxonomy_terms' => [],
            'event_parties' => [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => 'artist-1',
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => 'Artist One',
                        'slug' => 'artist-one',
                        'profile_type' => 'artist',
                        'avatar_url' => null,
                        'cover_url' => null,
                        'highlight' => false,
                        'genres' => ['rock'],
                        'taxonomy_terms' => [
                            ['type' => 'music_genre', 'value' => 'rock'],
                        ],
                    ],
                ],
            ],
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

    private function createActiveAttendanceCommitment(string $eventId, string $occurrenceId): void
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

    private function firstOccurrenceId(Event $event): string
    {
        $occurrenceIds = $this->occurrenceIdsForEvent($event);
        $this->assertNotSame([], $occurrenceIds);

        return $occurrenceIds[0];
    }

    /**
     * @return array<int, string>
     */
    private function occurrenceIdsForEvent(Event $event): array
    {
        $refs = $event->fresh()?->occurrence_refs ?? [];
        if ($refs instanceof \MongoDB\Model\BSONArray || $refs instanceof \MongoDB\Model\BSONDocument) {
            $refs = $refs->getArrayCopy();
        }

        if (is_array($refs) && $refs !== []) {
            $normalized = array_values(array_filter(array_map(function (mixed $ref): ?array {
                if ($ref instanceof \MongoDB\Model\BSONArray || $ref instanceof \MongoDB\Model\BSONDocument) {
                    $ref = $ref->getArrayCopy();
                }

                return is_array($ref) ? $ref : null;
            }, $refs)));
            usort($normalized, static fn (array $left, array $right): int => ((int) ($left['order'] ?? PHP_INT_MAX)) <=> ((int) ($right['order'] ?? PHP_INT_MAX)));

            return array_values(array_filter(array_map(static fn (array $ref): string => trim((string) ($ref['occurrence_id'] ?? '')), $normalized)));
        }

        return EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->get()
            ->map(static fn (EventOccurrence $occurrence): string => (string) $occurrence->_id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildAgendaPipelineForTest(array $filters, bool $useGeo): array
    {
        $method = new \ReflectionMethod(EventQueryService::class, 'buildAgendaPipeline');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $pipeline */
        $pipeline = $method->invoke(app(EventQueryService::class), $filters, 0, 10, $useGeo, null);

        return $pipeline;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildStreamPipelineForTest(array $filters, bool $useGeo): array
    {
        $method = new \ReflectionMethod(EventQueryService::class, 'buildStreamPipeline');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $pipeline */
        $pipeline = $method->invoke(app(EventQueryService::class), $filters, Carbon::now()->subMinute(), $useGeo, null);

        return $pipeline;
    }

    /**
     * @param  array<string, mixed>  $expression
     */
    private function matchExpressionContainsDocumentId(array $expression, string $documentId): bool
    {
        $inValues = data_get($expression, '_id.$in');
        if (is_array($inValues)) {
            foreach ($inValues as $value) {
                if ((string) $value === $documentId) {
                    return true;
                }
            }
        }

        foreach ($expression as $value) {
            if (is_array($value) && $this->matchExpressionContainsDocumentId($value, $documentId)) {
                return true;
            }
        }

        return false;
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
