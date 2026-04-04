<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\EventType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceReconciliationService;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class EventCrudControllerTest extends TestCaseTenant
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

    private AccountProfile $venue;

    private AccountProfile $artist;

    private EventType $eventType;

    private string $accountEventsBase;

    private string $tenantAdminEventsBase;

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
        EventType::query()->delete();
        TaxonomyTerm::query()->delete();
        Taxonomy::query()->delete();

        [$this->account] = $this->seedAccountWithRole(['*']);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['*']);

        Sanctum::actingAs($this->user, [
            'events:read',
            'events:create',
            'events:update',
            'events:delete',
        ]);

        $this->venue = $this->createAccountProfile('venue', 'Main Venue', $this->account);
        $this->artist = $this->createAccountProfile('artist', 'DJ Test');
        $this->eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#123456',
        ]);

        $this->accountEventsBase = "{$this->base_api_tenant}accounts/{$this->account->slug}/events";
        $this->tenantAdminEventsBase = "{$this->base_tenant_api_admin}events";

        $taxonomy = Taxonomy::create([
            'slug' => 'event_style',
            'name' => 'Event Style',
            'applies_to' => ['event'],
            'icon' => 'celebration',
            'color' => '#FFAA00',
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'showcase',
            'name' => 'Showcase',
        ]);
    }

    public function test_event_create_stores_event(): void
    {
        $payload = $this->makeEventPayload();

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', $payload['title']);
        $response->assertJsonPath('data.place_ref.id', (string) $this->venue->_id);
        $response->assertJsonPath('data.location.mode', 'physical');
        $response->assertJsonPath('data.publication.status', 'published');
        $this->assertSame($payload['type']['slug'], $response->json('data.type.slug'));

        $eventId = (string) $response->json('data.event_id');
        $this->assertTrue(
            EventOccurrence::query()
                ->where('event_id', $eventId)
                ->where('occurrence_index', 0)
                ->exists()
        );
    }

    public function test_event_create_stores_cover_upload_and_exposes_media_url(): void
    {
        Storage::fake('public');

        $payload = array_merge($this->makeEventPayload(), [
            'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
        ]);

        $response = $this->post($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $eventId = (string) $response->json('data.event_id');
        $thumbUrl = (string) $response->json('data.thumb.data.url');

        $this->assertNotSame('', $eventId);
        $this->assertNotSame('', $thumbUrl);
        $this->assertStringContainsString("/api/v1/media/events/{$eventId}/cover", $thumbUrl);

        $coverPaths = collect(Storage::disk('public')->allFiles())
            ->filter(static fn (string $path): bool => str_contains($path, "/events/{$eventId}/cover."));
        $this->assertTrue($coverPaths->isNotEmpty());

        $publicCoverPath = parse_url($thumbUrl, PHP_URL_PATH);
        $this->assertSame("/api/v1/media/events/{$eventId}/cover", $publicCoverPath);
        $this->get("{$this->base_tenant_url}api/v1/media/events/{$eventId}/cover")->assertOk();
        $this->get("{$this->base_tenant_url}events/{$eventId}/cover")->assertOk();
    }

    public function test_event_update_stores_cover_upload_and_exposes_media_url(): void
    {
        Storage::fake('public');

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $response = $this->patch(
            "{$this->accountEventsBase}/{$eventId}",
            ['cover' => UploadedFile::fake()->image('cover.jpg', 1400, 700)],
        );

        $response->assertStatus(200);
        $thumbUrl = (string) $response->json('data.thumb.data.url');
        $this->assertNotSame('', $thumbUrl);
        $this->assertStringContainsString("/api/v1/media/events/{$eventId}/cover", $thumbUrl);

        $coverPaths = collect(Storage::disk('public')->allFiles())
            ->filter(static fn (string $path): bool => str_contains($path, "/events/{$eventId}/cover."));
        $this->assertTrue($coverPaths->isNotEmpty());
        $this->get("{$this->base_tenant_url}api/v1/media/events/{$eventId}/cover")->assertOk();
    }

    public function test_event_update_remove_cover_clears_thumb_payload(): void
    {
        Storage::fake('public');

        $created = $this->post(
            $this->accountEventsBase,
            array_merge($this->makeEventPayload(), [
                'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
            ]),
        );
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $response = $this->patchJson(
            "{$this->accountEventsBase}/{$eventId}",
            ['remove_cover' => true],
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.thumb'));
        $coverPaths = collect(Storage::disk('public')->allFiles())
            ->filter(static fn (string $path): bool => str_contains($path, "/events/{$eventId}/cover."));
        $this->assertTrue($coverPaths->isEmpty());
    }

    public function test_event_create_rejects_unknown_event_type_id(): void
    {
        $payload = $this->makeEventPayload([
            'type' => [
                'id' => 'aaaaaaaaaaaaaaaaaaaaaaaa',
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type.id']);
    }

    public function test_events_index_supports_text_search_query_param(): void
    {
        $matching = $this->postJson(
            $this->accountEventsBase,
            $this->makeEventPayload([
                'title' => 'Noite Solar Search Match',
            ])
        );
        $matching->assertStatus(201);
        $matchingId = (string) $matching->json('data.event_id');

        $other = $this->postJson(
            $this->accountEventsBase,
            $this->makeEventPayload([
                'title' => 'Evento Aleatorio Sem Match',
            ])
        );
        $other->assertStatus(201);
        $otherId = (string) $other->json('data.event_id');

        $response = $this->getJson("{$this->accountEventsBase}?search=Solar&page=1&page_size=10");

        $response->assertStatus(200);
        $ids = collect($response->json('data') ?? [])
            ->map(static fn (array $item): string => (string) ($item['event_id'] ?? ''))
            ->all();

        $this->assertContains($matchingId, $ids);
        $this->assertNotContains($otherId, $ids);

        $partialResponse = $this->getJson("{$this->accountEventsBase}?search=Sola&page=1&page_size=10");
        $partialResponse->assertStatus(200);
        $partialIds = collect($partialResponse->json('data') ?? [])
            ->map(static fn (array $item): string => (string) ($item['event_id'] ?? ''))
            ->all();

        $this->assertContains($matchingId, $partialIds);
        $this->assertNotContains($otherId, $partialIds);

        $containsResponse = $this->getJson("{$this->accountEventsBase}?search=olar&page=1&page_size=10");
        $containsResponse->assertStatus(200);
        $containsIds = collect($containsResponse->json('data') ?? [])
            ->map(static fn (array $item): string => (string) ($item['event_id'] ?? ''))
            ->all();

        $this->assertContains($matchingId, $containsIds);
        $this->assertNotContains($otherId, $containsIds);
    }

    public function test_event_account_profile_candidates_endpoint_allows_read_create_or_update_ability_and_returns_filtered_candidates(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=main&page=1&page_size=20");

        $response->assertStatus(200);
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('per_page', 20);

        $venues = collect($response->json('data') ?? []);
        $matchedVenue = $venues->firstWhere('id', (string) $this->venue->_id);

        $this->assertNotNull($matchedVenue);
        $this->assertSame('venue', (string) ($matchedVenue['profile_type'] ?? ''));

        $partialResponse = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=mai&page=1&page_size=20");
        $partialResponse->assertStatus(200);
        $partialVenues = collect($partialResponse->json('data') ?? []);
        $this->assertNotNull($partialVenues->firstWhere('id', (string) $this->venue->_id));

        Sanctum::actingAs($landlord, ['events:create']);
        $createResponse = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=main");
        $createResponse->assertStatus(200);

        Sanctum::actingAs($landlord, ['events:update']);
        $updateResponse = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=main");
        $updateResponse->assertStatus(200);
    }

    public function test_event_account_profile_candidates_endpoint_paginates_artists_beyond_one_hundred_results(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        foreach (range(1, 102) as $index) {
            $this->createAccountProfile(
                'artist',
                sprintf('Zulu Artist %03d', $index)
            );
        }

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=artist&search=zulu&page=6&page_size=20");

        $response->assertStatus(200);
        $response->assertJsonPath('current_page', 6);
        $response->assertJsonPath('per_page', 20);
        $response->assertJsonPath('total', 102);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.display_name', 'Zulu Artist 101');
        $response->assertJsonPath('data.1.display_name', 'Zulu Artist 102');
    }

    public function test_event_account_profile_candidates_endpoint_includes_non_venue_profiles_when_poi_capability_is_enabled(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'restaurant'],
            [
                'label' => 'Restaurant',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                    'has_bio' => false,
                    'has_content' => false,
                    'has_taxonomies' => false,
                    'has_avatar' => false,
                    'has_cover' => false,
                    'has_events' => false,
                ],
            ]
        );

        $hostAccount = Account::create([
            'name' => 'Main Bistro Account',
            'document' => (string) Str::uuid(),
        ]);

        $host = AccountProfile::query()->create([
            'account_id' => (string) $hostAccount->_id,
            'profile_type' => 'restaurant',
            'display_name' => 'Main Bistro',
            'taxonomy_terms' => [],
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.1, -20.1],
            ],
            'is_active' => true,
            'is_verified' => false,
        ]);

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=bistro");

        $response->assertStatus(200);
        $hosts = collect($response->json('data') ?? []);
        $matched = $hosts->firstWhere('id', (string) $host->_id);
        $this->assertNotNull($matched);
        $this->assertSame('restaurant', (string) ($matched['profile_type'] ?? ''));
    }

    public function test_event_account_profile_candidates_endpoint_excludes_poi_enabled_profiles_without_valid_location(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'restaurant'],
            [
                'label' => 'Restaurant',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                    'has_bio' => false,
                    'has_content' => false,
                    'has_taxonomies' => false,
                    'has_avatar' => false,
                    'has_cover' => false,
                    'has_events' => false,
                ],
            ]
        );

        $hostAccount = Account::create([
            'name' => 'No Geo Bistro Account',
            'document' => (string) Str::uuid(),
        ]);

        $hostWithoutLocation = AccountProfile::query()->create([
            'account_id' => (string) $hostAccount->_id,
            'profile_type' => 'restaurant',
            'display_name' => 'No Geo Bistro',
            'taxonomy_terms' => [],
            'location' => null,
            'is_active' => true,
            'is_verified' => false,
        ]);

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=no%20geo");

        $response->assertStatus(200);
        $hosts = collect($response->json('data') ?? []);
        $matched = $hosts->firstWhere('id', (string) $hostWithoutLocation->_id);
        $this->assertNull($matched);
    }

    public function test_event_account_profile_candidates_endpoint_rejects_without_candidate_abilities(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['account-users:view']);

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=artist");

        $response->assertStatus(403);
    }

    public function test_account_events_account_profile_candidates_endpoint_uses_account_auth_boundary(): void
    {
        Sanctum::actingAs($this->user, ['events:create']);

        $response = $this->getJson("{$this->accountEventsBase}/account_profile_candidates?type=physical_host&search=main");

        $response->assertStatus(200);

        $venues = collect($response->json('data') ?? []);
        $matchedVenue = $venues->firstWhere('id', (string) $this->venue->_id);
        $this->assertNotNull($matchedVenue);
    }

    public function test_account_events_account_profile_candidates_endpoint_paginates_artists_beyond_one_hundred_results(): void
    {
        Sanctum::actingAs($this->user, ['events:create']);

        foreach (range(1, 102) as $index) {
            $this->createAccountProfile(
                'artist',
                sprintf('Scoped Zulu Artist %03d', $index)
            );
        }

        $response = $this->getJson("{$this->accountEventsBase}/account_profile_candidates?type=artist&search=scoped%20zulu&page=6&page_size=20");

        $response->assertStatus(200);
        $response->assertJsonPath('current_page', 6);
        $response->assertJsonPath('per_page', 20);
        $response->assertJsonPath('total', 102);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.display_name', 'Scoped Zulu Artist 101');
        $response->assertJsonPath('data.1.display_name', 'Scoped Zulu Artist 102');
    }

    public function test_event_create_persists_created_by_and_default_event_parties(): void
    {
        $venueSlug = 'main-venue-linked-'.Str::lower(Str::random(6));
        $artistSlug = 'dj-test-linked-'.Str::lower(Str::random(6));

        $this->venue->slug = $venueSlug;
        $this->venue->avatar_url = 'https://tenant.test/venue-avatar.png';
        $this->venue->cover_url = 'https://tenant.test/venue-cover.png';
        $this->venue->taxonomy_terms = [
            ['type' => 'event_style', 'value' => 'showcase'],
        ];
        $this->venue->save();

        $this->artist->slug = $artistSlug;
        $this->artist->avatar_url = 'https://tenant.test/artist-avatar.png';
        $this->artist->cover_url = 'https://tenant.test/artist-cover.png';
        $this->artist->taxonomy_terms = [
            ['type' => 'event_style', 'value' => 'showcase'],
        ];
        $this->artist->save();

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.created_by.type', 'account_user');
        $response->assertJsonPath('data.created_by.id', (string) $this->user->_id);

        $parties = collect($response->json('data.event_parties') ?? []);
        $venueParty = $parties->firstWhere('party_type', 'venue');
        $artistParty = $parties->firstWhere('party_type', 'artist');

        $this->assertNotNull($venueParty);
        $this->assertSame((string) $this->venue->_id, (string) ($venueParty['party_ref_id'] ?? ''));
        $this->assertTrue((bool) data_get($venueParty, 'permissions.can_edit', false));
        $this->assertSame('Main Venue', data_get($venueParty, 'metadata.display_name'));
        $this->assertSame($venueSlug, data_get($venueParty, 'metadata.slug'));
        $this->assertSame('venue', data_get($venueParty, 'metadata.profile_type'));
        $this->assertSame('https://tenant.test/venue-avatar.png', data_get($venueParty, 'metadata.avatar_url'));
        $this->assertSame('https://tenant.test/venue-cover.png', data_get($venueParty, 'metadata.cover_url'));
        $this->assertSame('Showcase', data_get($venueParty, 'metadata.taxonomy_terms.0.name'));

        $this->assertNotNull($artistParty);
        $this->assertSame((string) $this->artist->_id, (string) ($artistParty['party_ref_id'] ?? ''));
        $this->assertTrue((bool) data_get($artistParty, 'permissions.can_edit', false));
        $this->assertSame('DJ Test', data_get($artistParty, 'metadata.display_name'));
        $this->assertSame($artistSlug, data_get($artistParty, 'metadata.slug'));
        $this->assertSame('artist', data_get($artistParty, 'metadata.profile_type'));
        $this->assertSame('https://tenant.test/artist-avatar.png', data_get($artistParty, 'metadata.avatar_url'));
        $this->assertSame('https://tenant.test/artist-cover.png', data_get($artistParty, 'metadata.cover_url'));
        $this->assertSame('Showcase', data_get($artistParty, 'metadata.taxonomy_terms.0.name'));
    }

    public function test_event_create_rejects_legacy_single_date_payload_without_occurrences(): void
    {
        $now = Carbon::now();
        $payload = $this->makeEventPayload([
            'occurrences' => null,
            'date_time_start' => $now->copy()->addDay()->toISOString(),
            'date_time_end' => $now->copy()->addDay()->addHours(2)->toISOString(),
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences', 'date_time_start', 'date_time_end']);
    }

    public function test_event_create_dispatches_map_projection_sync_job_via_lifecycle_event(): void
    {
        Queue::fake();

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(201);
        $eventId = (string) $response->json('data.event_id');
        Queue::assertPushed(UpsertMapPoiFromEventJob::class, function (UpsertMapPoiFromEventJob $job) use ($eventId): bool {
            return (string) $this->readPrivateProperty($job, 'eventId') === $eventId;
        });
    }

    public function test_event_create_rejects_unknown_taxonomy(): void
    {
        $payload = $this->makeEventPayload([
            'taxonomy_terms' => [
                ['type' => 'unknown', 'value' => 'value'],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function test_event_create_accepts_allowed_taxonomy(): void
    {
        $payload = $this->makeEventPayload([
            'taxonomy_terms' => [
                ['type' => 'event_style', 'value' => 'showcase'],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.taxonomy_terms.0.type', 'event_style');
        $response->assertJsonPath('data.taxonomy_terms.0.value', 'showcase');
    }

    public function test_event_create_rejects_scheduled_without_publish_at(): void
    {
        $payload = $this->makeEventPayload([
            'publication' => [
                'status' => 'publish_scheduled',
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function test_event_create_rejects_non_artist_ids(): void
    {
        $payload = $this->makeEventPayload([
            'artist_ids' => [(string) $this->venue->_id],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function test_event_create_rejects_unknown_event_party_type(): void
    {
        $payload = $this->makeEventPayload([
            'event_parties' => [
                [
                    'party_type' => 'unknown_party',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_parties.0.party_type']);
    }

    public function test_event_create_rejects_physical_host_without_location(): void
    {
        [$extraAccount] = $this->seedAccountWithRole(['*']);
        $venue = AccountProfile::create([
            'account_id' => (string) $extraAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'No Location Venue',
            'taxonomy_terms' => [],
            'is_active' => true,
            'is_verified' => false,
        ]);

        $payload = $this->makeEventPayload([
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $venue->_id,
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function test_event_create_online_allows_missing_place_ref(): void
    {
        $payload = $this->makeEventPayload([
            'location' => [
                'mode' => 'online',
                'online' => [
                    'url' => 'https://meet.example.org/events-room',
                    'platform' => 'jitsi',
                ],
            ],
            'place_ref' => null,
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.location.mode', 'online');
        $response->assertJsonPath('data.place_ref', null);
    }

    public function test_event_create_online_requires_online_payload(): void
    {
        $payload = $this->makeEventPayload([
            'location' => [
                'mode' => 'online',
            ],
            'place_ref' => null,
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location.online']);
    }

    public function test_event_create_hybrid_requires_both_place_ref_and_online_payload(): void
    {
        $missingPlaceRef = $this->makeEventPayload([
            'location' => [
                'mode' => 'hybrid',
                'online' => [
                    'url' => 'https://meet.example.org/events-room',
                ],
            ],
            'place_ref' => null,
        ]);

        $response = $this->postJson($this->accountEventsBase, $missingPlaceRef);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['place_ref']);

        $missingOnline = $this->makeEventPayload([
            'location' => [
                'mode' => 'hybrid',
            ],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $missingOnline);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['location.online']);
    }

    public function test_event_create_accepts_missing_content_description(): void
    {
        $payload = $this->makeEventPayload([
            'content' => '',
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.content', '');
    }

    public function test_event_create_accepts_missing_content_field(): void
    {
        $payload = $this->makeEventPayload();
        unset($payload['content']);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.content', '');
    }

    public function test_event_create_forbidden_without_ability(): void
    {
        $limited = $this->createAccountUser(['*']);
        Sanctum::actingAs($limited, ['events:read']);

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(403);
    }

    public function test_event_index_filters_by_status(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $this->createEvent([
            'title' => 'Draft Event',
            'publication' => ['status' => 'draft'],
        ]);
        $this->createEvent([
            'title' => 'Published Event',
            'publication' => ['status' => 'published', 'publish_at' => Carbon::now()->subMinute()],
        ]);

        $response = $this->getJson("{$this->tenantAdminEventsBase}?status=published");

        $response->assertStatus(200);
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame('Published Event', $items[0]['title']);
    }

    public function test_event_update_changes_fields(): void
    {
        $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $createResponse->assertStatus(201);
        $eventId = $createResponse->json('data.event_id');
        $this->assertNotEmpty($eventId);
        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'Updated Title',
            'publication' => ['status' => 'ended'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Updated Title');
        $response->assertJsonPath('data.publication.status', 'ended');
    }

    public function test_event_update_dispatches_map_projection_sync_job_via_lifecycle_event(): void
    {
        Queue::fake();
        $event = $this->createEvent();
        $eventId = (string) $event->_id;

        $response = $this->patchJson("{$this->accountEventsBase}/{$event->_id}", [
            'title' => 'Updated Via Queue Assertion',
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(UpsertMapPoiFromEventJob::class, function (UpsertMapPoiFromEventJob $job) use ($eventId): bool {
            return (string) $this->readPrivateProperty($job, 'eventId') === $eventId;
        });
    }

    public function test_event_create_rejects_multiple_occurrences_when_capability_is_not_effective(): void
    {
        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
            'capabilities' => [
                'multiple_occurrences' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences']);
    }

    public function test_event_create_exposes_map_poi_capability_by_default_when_tenant_allows_it(): void
    {
        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.capabilities.map_poi.enabled', true);
    }

    public function test_event_create_hides_map_poi_capability_when_tenant_disables_it(): void
    {
        $this->patchEventsSettings([
            'capabilities.map_poi.available' => false,
        ])->assertStatus(200);

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(201);
        $this->assertNull($response->json('data.capabilities.map_poi'));

        $this->patchEventsSettings([
            'capabilities.map_poi.available' => true,
        ])->assertStatus(200);
    }

    public function test_event_create_projects_map_poi_with_event_type_icon_color_snapshot(): void
    {
        $this->eventType->fill([
            'icon' => 'music_note',
            'color' => '#C6141F',
            'icon_color' => '#101010',
        ])->save();

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $response->assertStatus(201);

        $eventId = (string) $response->json('data.event_id');
        $event = Event::query()->find($eventId);
        $this->assertNotNull($event);

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event);

        $projection = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame('icon', data_get($projection->visual, 'mode'));
        $this->assertSame('music_note', data_get($projection->visual, 'icon'));
        $this->assertSame('#C6141F', data_get($projection->visual, 'color'));
        $this->assertSame('#101010', data_get($projection->visual, 'icon_color'));
        $this->assertSame('type_definition', data_get($projection->visual, 'source'));
    }

    public function test_event_create_online_supports_range_discovery_scope_for_map_poi_projection(): void
    {
        $this->patchEventsSettings([
            'capabilities.map_poi.available' => true,
        ])->assertStatus(200);

        $payload = $this->makeEventPayload([
            'location' => [
                'mode' => 'online',
                'online' => [
                    'url' => 'https://meet.example.org/events-room',
                    'platform' => 'jitsi',
                ],
            ],
            'place_ref' => null,
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                    'discovery_scope' => [
                        'type' => 'range',
                        'center' => [
                            'type' => 'Point',
                            'coordinates' => [-39.99, -20.01],
                        ],
                        'radius_meters' => 8000,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.capabilities.map_poi.enabled', true);
        $response->assertJsonPath('data.capabilities.map_poi.discovery_scope.type', 'range');
        $eventId = (string) $response->json('data.event_id');
        $event = Event::query()->find($eventId);
        $this->assertNotNull($event);
        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event);

        $poi = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();

        $this->assertNotNull($poi);
        $this->assertTrue((bool) ($poi->is_active ?? false));
        $this->assertSame('range', data_get($poi->discovery_scope, 'type'));
        $this->assertEquals(-39.99, (float) data_get($poi->location, 'coordinates.0'));
        $this->assertEquals(-20.01, (float) data_get($poi->location, 'coordinates.1'));
    }

    public function test_event_map_poi_projection_soft_hides_when_occurrences_become_stale(): void
    {
        $baseline = Carbon::parse('2026-03-01T10:00:00+00:00');
        Carbon::setTestNow($baseline);

        try {
            $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
                'occurrences' => [[
                    'date_time_start' => $baseline->copy()->addHour()->toISOString(),
                ]],
            ]));
            $createResponse->assertStatus(201);

            $eventId = (string) $createResponse->json('data.event_id');
            $event = Event::query()->find($eventId);
            $this->assertNotNull($event);
            $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event);
            $this->assertTrue(
                MapPoi::query()
                    ->where('ref_type', 'event')
                    ->where('ref_id', $eventId)
                    ->where('is_active', true)
                    ->exists()
            );

            Carbon::setTestNow($baseline->copy()->addHours(8));

            $updateResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
                'occurrences' => [[
                    'date_time_start' => $baseline->copy()->subHours(5)->toISOString(),
                ]],
            ]);
            $updateResponse->assertStatus(200);
            $updated = Event::query()->find($eventId);
            $this->assertNotNull($updated);
            $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($updated);

            $poi = MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $eventId)
                ->first();

            $this->assertNotNull($poi);
            $this->assertFalse((bool) ($poi->is_active ?? true));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_event_map_poi_capability_disable_and_reenable_is_non_destructive(): void
    {
        $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $createResponse->assertStatus(201);
        $eventId = (string) $createResponse->json('data.event_id');
        $created = Event::query()->find($eventId);
        $this->assertNotNull($created);
        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($created);

        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $eventId)
                ->where('is_active', true)
                ->exists()
        );

        $disableResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'capabilities' => [
                'map_poi' => [
                    'enabled' => false,
                ],
            ],
        ]);
        $disableResponse->assertStatus(200);
        $disabledEvent = Event::query()->find($eventId);
        $this->assertNotNull($disabledEvent);
        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($disabledEvent);

        $disabledPoi = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();
        $this->assertNotNull($disabledPoi);
        $this->assertFalse((bool) ($disabledPoi->is_active ?? true));

        $enableResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                ],
            ],
        ]);
        $enableResponse->assertStatus(200);
        $enabledEvent = Event::query()->find($eventId);
        $this->assertNotNull($enabledEvent);
        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($enabledEvent);

        $reenabledPoi = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();
        $this->assertNotNull($reenabledPoi);
        $this->assertTrue((bool) ($reenabledPoi->is_active ?? false));
    }

    public function test_event_map_poi_projection_ignores_stale_checkpoint_write(): void
    {
        $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $createResponse->assertStatus(201);

        $eventId = (string) $createResponse->json('data.event_id');
        $event = Event::query()->find($eventId);
        $this->assertNotNull($event);

        $projectionService = $this->app->make(MapPoiProjectionService::class);
        $projectionService->upsertFromEvent($event);

        $poi = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();
        $this->assertNotNull($poi);

        $lockedCheckpoint = (int) Carbon::now()->addDay()->valueOf();
        $poi->fill([
            'source_checkpoint' => $lockedCheckpoint,
            'name' => 'Locked POI Name',
        ]);
        $poi->save();

        $projectionService->upsertFromEvent($event->fresh());

        $freshPoi = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();
        $this->assertNotNull($freshPoi);
        $this->assertSame($lockedCheckpoint, (int) ($freshPoi->source_checkpoint ?? 0));
        $this->assertSame('Locked POI Name', (string) ($freshPoi->name ?? ''));
    }

    public function test_event_create_allows_multiple_occurrences_when_tenant_settings_enable_it(): void
    {
        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => true,
            'capabilities.multiple_occurrences.max_occurrences' => 2,
        ])->assertStatus(200);

        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
            'capabilities' => [
                'multiple_occurrences' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.occurrences'));
        $response->assertJsonPath('data.capabilities.multiple_occurrences.enabled', true);
        $eventId = (string) $response->json('data.event_id');
        $this->assertSame(
            2,
            EventOccurrence::query()->where('event_id', $eventId)->count()
        );
    }

    public function test_event_create_rejects_above_tenant_max_occurrences(): void
    {
        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => true,
            'capabilities.multiple_occurrences.max_occurrences' => 2,
        ])->assertStatus(200);

        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(3),
            'capabilities' => [
                'multiple_occurrences' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences']);
    }

    public function test_event_create_treats_zero_tenant_max_as_null_limit(): void
    {
        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => true,
            'capabilities.multiple_occurrences.max_occurrences' => 0,
        ])->assertStatus(200);

        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(3),
            'capabilities' => [
                'multiple_occurrences' => [
                    'enabled' => true,
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('data.occurrences'));
    }

    public function test_event_update_without_schedule_mutation_keeps_stored_occurrences_when_tenant_disables_capability(): void
    {
        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => true,
        ])->assertStatus(200);

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
            'capabilities' => [
                'multiple_occurrences' => [
                    'enabled' => true,
                ],
            ],
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');

        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => false,
        ])->assertStatus(200);

        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'Renamed without touching schedule',
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.occurrences'));
        $this->assertNull($response->json('data.capabilities.multiple_occurrences'));
    }

    public function test_event_update_with_schedule_mutation_rejects_multiple_occurrences_when_capability_not_effective(): void
    {
        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => true,
        ])->assertStatus(200);

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
            'capabilities' => [
                'multiple_occurrences' => [
                    'enabled' => true,
                ],
            ],
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');

        $this->patchEventsSettings([
            'capabilities.multiple_occurrences.allow_multiple' => false,
        ])->assertStatus(200);

        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'occurrences' => $this->makeOccurrences(2),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences']);
    }

    public function test_event_update_returns404_when_missing(): void
    {
        $response = $this->patchJson("{$this->accountEventsBase}/missing-event", [
            'title' => 'Missing',
        ]);

        $response->assertStatus(404);
    }

    public function test_event_delete_soft_deletes(): void
    {
        $event = $this->createEvent();

        $response = $this->deleteJson("{$this->accountEventsBase}/{$event->_id}");

        $response->assertStatus(200);

        $deleted = Event::withTrashed()->find($event->_id);
        $this->assertNotNull($deleted?->deleted_at);
        $occurrence = EventOccurrence::withTrashed()->where('event_id', (string) $event->_id)->first();
        $this->assertNotNull($occurrence?->deleted_at);
    }

    public function test_event_delete_dispatches_map_projection_delete_job_via_lifecycle_event(): void
    {
        Queue::fake();
        $event = $this->createEvent();
        $eventId = (string) $event->_id;

        $response = $this->deleteJson("{$this->accountEventsBase}/{$event->_id}");

        $response->assertStatus(200);
        Queue::assertPushed(DeleteMapPoiByRefJob::class, function (DeleteMapPoiByRefJob $job) use ($eventId): bool {
            return (string) $this->readPrivateProperty($job, 'refType') === 'event'
                && (string) $this->readPrivateProperty($job, 'refId') === $eventId;
        });
    }

    public function test_publish_scheduled_events_job_promotes_ready_events(): void
    {
        $ready = $this->createEvent([
            'title' => 'Ready Event',
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => Carbon::now()->subMinute(),
            ],
        ]);

        $future = $this->createEvent([
            'title' => 'Future Event',
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => Carbon::now()->addDay(),
            ],
        ]);

        app()->call([new PublishScheduledEventsJob, 'handle']);

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

        $ready->refresh();
        $future->refresh();

        $readyPublication = is_array($ready->publication) ? $ready->publication : (array) $ready->publication;
        $futurePublication = is_array($future->publication) ? $future->publication : (array) $future->publication;

        $this->assertSame('published', $readyPublication['status'] ?? null);
        $this->assertSame('publish_scheduled', $futurePublication['status'] ?? null);

        $readyOccurrence = EventOccurrence::query()->where('event_id', (string) $ready->_id)->first();
        $futureOccurrence = EventOccurrence::query()->where('event_id', (string) $future->_id)->first();
        $this->assertNotNull($readyOccurrence);
        $this->assertNotNull($futureOccurrence);
        $this->assertTrue((bool) ($readyOccurrence->is_event_published ?? false));
        $this->assertFalse((bool) ($futureOccurrence->is_event_published ?? false));

        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', (string) $ready->_id)
                ->exists()
        );
        $this->assertFalse(
            MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', (string) $future->_id)
                ->exists()
        );
    }

    public function test_publish_scheduled_events_job_emits_stream_delta_after_publication_transition(): void
    {
        $baseline = Carbon::parse('2026-03-01T10:00:00+00:00');
        Carbon::setTestNow($baseline);

        try {
            $event = $this->createEvent([
                'title' => 'Scheduled Stream Transition Event',
                'publication' => [
                    'status' => 'publish_scheduled',
                    'publish_at' => $baseline->copy()->subMinute(),
                ],
            ]);

            $cursor = $baseline->copy()->addSecond()->toISOString();

            Carbon::setTestNow($baseline->copy()->addMinutes(5));
            app()->call([new PublishScheduledEventsJob, 'handle']);

            Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

            $response = $this->get(
                "{$this->base_api_tenant}events/stream",
                [
                    'Last-Event-ID' => $cursor,
                    'Accept' => 'text/event-stream',
                ]
            );

            $response->assertStatus(200);
            $content = $response->streamedContent();
            $this->assertStringContainsString('occurrence.updated', $content);
            $this->assertStringContainsString((string) $event->_id, $content);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_event_occurrence_reconciliation_syncs_mirrored_fields_from_event(): void
    {
        $event = $this->createEvent([
            'title' => 'Initial Title',
        ]);
        $eventId = (string) $event->_id;

        EventOccurrence::query()
            ->where('event_id', $eventId)
            ->update([
                'title' => 'Stale Occurrence Title',
            ]);

        $event->title = 'Canonical Event Title';
        $event->save();

        app(EventOccurrenceReconciliationService::class)->reconcileAllTenants();
        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

        $occurrence = EventOccurrence::query()->where('event_id', $eventId)->first();
        $this->assertNotNull($occurrence);
        $this->assertSame('Canonical Event Title', (string) $occurrence->title);
    }

    public function test_event_occurrence_reconciliation_soft_deletes_occurrences_for_deleted_events(): void
    {
        $event = $this->createEvent([
            'title' => 'Deleted Event',
        ]);
        $eventId = (string) $event->_id;

        Event::query()->where('_id', $event->_id)->firstOrFail()->delete();

        app(EventOccurrenceReconciliationService::class)->reconcileAllTenants();
        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

        $occurrence = EventOccurrence::withTrashed()->where('event_id', $eventId)->first();
        $this->assertNotNull($occurrence);
        $this->assertNotNull($occurrence->deleted_at);
    }

    public function test_tenant_admin_create_uses_venue_without_on_behalf_account_params(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:create']);

        $payload = $this->makeEventPayload();

        $response = $this->postJson($this->tenantAdminEventsBase, $payload);

        $response->assertStatus(201);
    }

    public function test_tenant_admin_create_on_behalf_is_scoped_to_target_account(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:create', 'events:read']);

        $payload = $this->makeEventPayload();

        $response = $this->postJson($this->tenantAdminEventsBase, $payload);
        $response->assertStatus(201);

        $accountList = $this->getJson($this->accountEventsBase);
        $accountList->assertStatus(200);
        $this->assertCount(1, $accountList->json('data'));

        $otherAccount = Account::create([
            'name' => 'Other Account',
            'document' => (string) Str::uuid(),
        ]);
        $otherBase = "{$this->base_api_tenant}accounts/{$otherAccount->slug}/events";

        $otherList = $this->getJson($otherBase);
        $otherList->assertStatus(200);
        $this->assertCount(0, $otherList->json('data'));
    }

    public function test_account_user_cannot_manage_another_account_events(): void
    {
        $event = $this->createEvent();

        $otherAccount = Account::create([
            'name' => 'Second Account',
            'document' => (string) Str::uuid(),
        ]);

        $role = $otherAccount->roleTemplates()->create([
            'name' => 'Other Events Role',
            'permissions' => ['*'],
        ]);

        $otherUser = $this->userService->create($otherAccount, [
            'name' => 'Other User',
            'email' => uniqid('other-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        Sanctum::actingAs($otherUser, ['events:read', 'events:update']);

        $otherBase = "{$this->base_api_tenant}accounts/{$otherAccount->slug}/events/{$event->_id}";
        $response = $this->patchJson($otherBase, [
            'title' => 'Should Not Update',
        ]);

        $response->assertStatus(404);
    }

    public function test_account_user_can_manage_event_when_event_party_can_edit_is_true(): void
    {
        $event = $this->createEvent();

        $otherAccount = Account::create([
            'name' => 'Editable Account',
            'document' => (string) Str::uuid(),
        ]);
        $otherVenue = $this->createAccountProfile('venue', 'Editable Venue', $otherAccount);

        $event->event_parties = [
            [
                'party_type' => 'venue',
                'party_ref_id' => (string) $otherVenue->_id,
                'permissions' => ['can_edit' => true],
            ],
        ];
        $event->save();

        $role = $otherAccount->roleTemplates()->create([
            'name' => 'Editable Role',
            'permissions' => ['*'],
        ]);

        $otherUser = $this->userService->create($otherAccount, [
            'name' => 'Editable User',
            'email' => uniqid('editable-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        Sanctum::actingAs($otherUser, ['events:read', 'events:update']);

        $otherBase = "{$this->base_api_tenant}accounts/{$otherAccount->slug}/events/{$event->_id}";
        $response = $this->patchJson($otherBase, [
            'title' => 'Updated By Shared Party',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Updated By Shared Party');
    }

    public function test_account_member_cannot_manage_event_when_event_party_can_edit_is_false(): void
    {
        $event = $this->createEvent();

        $event->event_parties = [
            [
                'party_type' => 'venue',
                'party_ref_id' => (string) $this->venue->_id,
                'permissions' => ['can_edit' => false],
            ],
        ];
        $event->save();

        $otherUser = $this->createAccountUser(['*']);
        Sanctum::actingAs($otherUser, ['events:read', 'events:update']);

        $response = $this->patchJson("{$this->accountEventsBase}/{$event->_id}", [
            'title' => 'Should Not Update',
        ]);

        $response->assertStatus(404);
    }

    public function test_event_owner_can_manage_event_when_party_can_edit_is_false(): void
    {
        $event = $this->createEvent();

        $event->event_parties = [
            [
                'party_type' => 'venue',
                'party_ref_id' => (string) $this->venue->_id,
                'permissions' => ['can_edit' => false],
            ],
        ];
        $event->save();

        Sanctum::actingAs($this->user, ['events:read', 'events:update']);

        $response = $this->patchJson("{$this->accountEventsBase}/{$event->_id}", [
            'title' => 'Updated By Owner Override',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Updated By Owner Override');
    }

    public function test_event_owner_can_manage_event_without_matching_event_party(): void
    {
        $event = $this->createEvent();

        $otherAccount = Account::create([
            'name' => 'Detached Ownership Account',
            'document' => (string) Str::uuid(),
        ]);
        $otherVenue = $this->createAccountProfile('venue', 'Detached Ownership Venue', $otherAccount);

        $event->event_parties = [
            [
                'party_type' => 'venue',
                'party_ref_id' => (string) $otherVenue->_id,
                'permissions' => ['can_edit' => false],
            ],
        ];
        $event->save();

        Sanctum::actingAs($this->user, ['events:read', 'events:update']);

        $response = $this->patchJson("{$this->accountEventsBase}/{$event->_id}", [
            'title' => 'Updated By Owner Without Matching Party',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Updated By Owner Without Matching Party');
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Events Role '.uniqid('role-', true),
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Events User',
            'email' => uniqid('events-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createAccountProfile(string $profileType, string $displayName, ?Account $account = null): AccountProfile
    {
        $account = $account ?? Account::create([
            'name' => $displayName.' Account',
            'document' => (string) Str::uuid(),
        ]);

        $location = null;
        if ($profileType === 'venue') {
            $location = [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ];
        }

        return AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => $profileType,
            'display_name' => $displayName,
            'taxonomy_terms' => [],
            'location' => $location,
            'is_active' => true,
            'is_verified' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeEventPayload(array $overrides = []): array
    {
        $now = Carbon::now();

        return array_merge([
            'title' => 'Sample Event',
            'content' => 'Event content',
            'location' => [
                'mode' => 'physical',
            ],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
            'artist_ids' => [(string) $this->artist->_id],
            'type' => [
                'id' => (string) $this->eventType->_id,
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
                'description' => (string) $this->eventType->description,
            ],
            'occurrences' => [[
                'date_time_start' => $now->copy()->addDay()->setHour(20)->setMinute(0)->setSecond(0)->toISOString(),
                'date_time_end' => $now->copy()->addDay()->setHour(22)->setMinute(0)->setSecond(0)->toISOString(),
            ]],
            'tags' => ['music'],
            'categories' => ['culture'],
            'taxonomy_terms' => [],
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute()->toISOString(),
            ],
        ], $overrides);
    }

    private function createEvent(array $overrides = []): Event
    {
        $now = Carbon::now();

        $event = Event::create(array_merge([
            'title' => 'Stored Event',
            'content' => 'Event content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
                'metadata' => [
                    'display_name' => $this->venue->display_name,
                ],
            ],
            'type' => [
                'id' => (string) $this->eventType->_id,
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
                'description' => (string) $this->eventType->description,
                'icon' => (string) $this->eventType->icon,
                'color' => (string) $this->eventType->color,
            ],
            'venue' => [
                'id' => (string) $this->venue->_id,
                'display_name' => $this->venue->display_name,
                'tagline' => null,
                'hero_image_url' => null,
                'logo_url' => null,
                'taxonomy_terms' => [],
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
                    'id' => (string) $this->artist->_id,
                    'display_name' => $this->artist->display_name,
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
            'created_by' => [
                'type' => 'account_user',
                'id' => (string) $this->user->_id,
            ],
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => true],
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

    /**
     * @return array<int, array{date_time_start: string, date_time_end: string}>
     */
    private function makeOccurrences(int $count): array
    {
        $now = Carbon::now()->addDay();
        $occurrences = [];

        for ($index = 0; $index < $count; $index++) {
            $start = $now->copy()->addDays($index)->setHour(20)->setMinute(0)->setSecond(0);
            $occurrences[] = [
                'date_time_start' => $start->toISOString(),
                'date_time_end' => $start->copy()->addHours(2)->toISOString(),
            ];
        }

        return $occurrences;
    }

    private function patchEventsSettings(array $payload): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), [
            'events:read',
            'map-pois-settings:update',
        ]);

        $response = $this->patchJson("{$this->base_tenant_api_admin}settings/values/events", $payload);

        Sanctum::actingAs($this->user, [
            'events:read',
            'events:create',
            'events:update',
            'events:delete',
        ]);

        return $response;
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
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
