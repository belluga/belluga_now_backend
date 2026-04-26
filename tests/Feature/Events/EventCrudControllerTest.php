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
use Belluga\Events\Support\Validation\InputConstraints;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Jobs\CleanupOrphanedMapPoisJob;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
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

    private AccountProfile $band;

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

        Event::withTrashed()->forceDelete();
        EventOccurrence::withTrashed()->forceDelete();
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
        $this->band = $this->createAccountProfile('band', 'Banda Teste');
        $this->venue->slug = 'main-venue-'.Str::lower(Str::random(6));
        $this->venue->save();
        $this->artist->slug = 'dj-test-'.Str::lower(Str::random(6));
        $this->artist->save();
        $this->band->slug = 'banda-teste-'.Str::lower(Str::random(6));
        $this->band->save();
        $this->eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#123456',
            'allowed_taxonomies' => ['event_style'],
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

    public function test_event_create_sanitizes_content_html_subset_and_preserves_emojis(): void
    {
        $payload = $this->makeEventPayload([
            'content' => '<p><strong>Evento 🎉</strong> <u>underline</u> <a href="https://example.com">link</a> <s>riscado</s></p>',
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $eventId = (string) $response->json('data.event_id');
        $stored = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $expected = '<p><strong>Evento 🎉</strong> underline link <s>riscado</s></p>';
        $this->assertSame($expected, (string) $response->json('data.content'));
        $this->assertSame($expected, $stored->content);
        $this->assertSame($stored->content, $occurrence->content);
        $this->assertStringNotContainsString('<u>', $stored->content);
        $this->assertStringNotContainsString('<a', $stored->content);
        $this->assertStringContainsString('<s>riscado</s>', $stored->content);
        $this->assertStringContainsString('🎉', $stored->content);
    }

    public function test_event_create_strips_media_only_content_to_empty_string(): void
    {
        $payload = $this->makeEventPayload([
            'content' => '<p><img src="https://example.com/banner.png" alt="Banner" /></p><p><br /></p>',
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $eventId = (string) $response->json('data.event_id');
        $stored = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $this->assertSame('', (string) $response->json('data.content'));
        $this->assertSame('', (string) $stored->content);
        $this->assertSame('', (string) $occurrence->content);
    }

    public function test_event_update_sanitizes_plain_text_content_with_line_breaks(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $response = $this->patchJson(
            "{$this->accountEventsBase}/{$eventId}",
            [
                'content' => "Linha 1 🎉\nLinha 2",
            ],
        );

        $response->assertStatus(200);
        $expected = '<p>Linha 1 🎉<br />Linha 2</p>';
        $stored = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $this->assertSame($expected, (string) $response->json('data.content'));
        $this->assertSame($expected, $stored->content);
        $this->assertSame($expected, $occurrence->content);
        $this->assertStringContainsString('🎉', $stored->content);
    }

    public function test_event_content_limit_is_100kb_after_sanitization(): void
    {
        $exact = $this->htmlParagraphOfSanitizedByteLength(102400);
        $overLimit = $this->htmlParagraphOfSanitizedByteLength(102401);

        $accepted = $this->postJson(
            $this->accountEventsBase,
            $this->makeEventPayload([
                'content' => $exact,
            ])
        );

        $accepted->assertStatus(201);
        $this->assertSame(102400, strlen((string) $accepted->json('data.content')));

        $rejected = $this->postJson(
            $this->accountEventsBase,
            $this->makeEventPayload([
                'content' => $overLimit,
            ])
        );

        $rejected->assertStatus(422);
        $rejected->assertJsonValidationErrors(['content']);
    }

    public function test_event_create_resolves_dynamic_account_profile_party_type_from_profile_id_and_keeps_admin_and_public_read_models_separate(): void
    {
        $payload = $this->makeEventPayload([
            'event_parties' => [[
                'party_ref_id' => (string) $this->band->_id,
                'permissions' => ['can_edit' => true],
            ]],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.event_parties.0.party_type', (string) $this->band->profile_type);
        $response->assertJsonPath('data.event_parties.0.metadata.profile_type', (string) $this->band->profile_type);
        $response->assertJsonPath('data.linked_account_profiles.0.profile_type', (string) $this->band->profile_type);
        $response->assertJsonPath('data.linked_account_profiles.0.slug', (string) $this->band->slug);
        $this->assertNull(data_get($response->json(), 'data.artists'));

        $stored = Event::query()->findOrFail((string) $response->json('data.event_id'));
        $this->assertNull(data_get($stored->getAttributes(), 'artists'));

        $publicResponse = $this->getJson("{$this->base_api_tenant}events/{$stored->_id}");
        $publicResponse->assertStatus(200);
        $publicResponse->assertJsonPath('data.artists.0.profile_type', (string) $this->band->profile_type);
        $publicResponse->assertJsonPath('data.artists.0.slug', (string) $this->band->slug);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $adminResponse = $this->getJson($this->tenantAdminEventsBase);
        $adminResponse->assertStatus(200);
        $adminEvent = collect($adminResponse->json('data') ?? [])
            ->firstWhere('event_id', (string) $stored->_id);
        $this->assertIsArray($adminEvent);
        $this->assertArrayNotHasKey('artists', $adminEvent);
        $this->assertSame((string) $this->band->profile_type, data_get($adminEvent, 'linked_account_profiles.0.profile_type'));
        $this->assertSame((string) $this->band->slug, data_get($adminEvent, 'linked_account_profiles.0.slug'));
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

    public function test_event_index_filters_by_venue_profile_id(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $secondaryVenue = $this->createAccountProfile('venue', 'Secondary Venue');

        $this->createEvent([
            'title' => 'Main Venue Filter Match',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
            'venue' => [
                'id' => (string) $this->venue->_id,
                'display_name' => $this->venue->display_name,
                'tagline' => null,
                'hero_image_url' => null,
                'logo_url' => null,
                'taxonomy_terms' => [],
            ],
        ]);

        $this->createEvent([
            'title' => 'Secondary Venue Filter Miss',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $secondaryVenue->_id,
            ],
            'venue' => [
                'id' => (string) $secondaryVenue->_id,
                'display_name' => $secondaryVenue->display_name,
                'tagline' => null,
                'hero_image_url' => null,
                'logo_url' => null,
                'taxonomy_terms' => [],
            ],
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?venue_profile_id={$this->venue->_id}"
        );

        $response->assertStatus(200);
        $this->assertSame(
            ['Main Venue Filter Match'],
            collect($response->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_event_index_filters_by_related_account_profile_id_without_matching_venue_semantics(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $this->createEvent([
            'title' => 'Band Related Filter Match',
            'event_parties' => [
                [
                    'party_type' => (string) $this->band->profile_type,
                    'party_ref_id' => (string) $this->band->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->band->display_name,
                        'slug' => (string) $this->band->slug,
                        'profile_type' => (string) $this->band->profile_type,
                        'avatar_url' => $this->band->avatar_url,
                        'cover_url' => $this->band->cover_url,
                        'taxonomy_terms' => is_array($this->band->taxonomy_terms ?? null)
                            ? $this->band->taxonomy_terms
                            : [],
                    ],
                ],
            ],
        ]);

        $this->createEvent([
            'title' => 'Band Venue-shaped Filter Miss',
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->band->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->band->display_name,
                        'slug' => (string) $this->band->slug,
                        'profile_type' => (string) $this->band->profile_type,
                    ],
                ],
            ],
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?related_account_profile_id={$this->band->_id}"
        );

        $response->assertStatus(200);
        $this->assertSame(
            ['Band Related Filter Match'],
            collect($response->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_event_index_filters_by_specific_date(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $targetDate = Carbon::now()->startOfDay()->addDays(3)->setHour(20);

        $this->createEvent([
            'title' => 'Specific Date Match',
            'date_time_start' => $targetDate,
            'date_time_end' => $targetDate->copy()->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Specific Date Miss',
            'date_time_start' => $targetDate->copy()->addDay(),
            'date_time_end' => $targetDate->copy()->addDay()->addHours(2),
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?date={$targetDate->toDateString()}"
        );

        $response->assertStatus(200);
        $this->assertSame(
            ['Specific Date Match'],
            collect($response->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_event_index_rejects_legacy_search_query_param(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?search=legacy"
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['search']);
    }

    public function test_event_index_rejects_page_size_above_safe_maximum(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $this->createEvent([
            'title' => 'Page Size Clamp Event',
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?page=1&page_size=999"
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page_size']);
    }

    public function test_public_event_index_rejects_page_size_above_public_safe_maximum(): void
    {
        $this->createEvent([
            'title' => 'Public Page Size Guard Event',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}events?page=1&page_size=".(InputConstraints::PUBLIC_PAGE_SIZE_MAX + 1)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page_size']);
    }

    public function test_public_event_index_rejects_page_above_public_safe_depth(): void
    {
        $this->createEvent([
            'title' => 'Public Page Guard Event',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}events?page=".(InputConstraints::PUBLIC_PAGE_MAX + 1).'&page_size=10'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page']);
    }

    public function test_event_index_uses_stable_tie_break_order_for_matching_start_times(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $sharedStart = Carbon::now()->startOfDay()->addDays(7)->setHour(20);

        $older = $this->createEvent([
            'title' => 'Same Start Older',
            'date_time_start' => $sharedStart,
            'date_time_end' => $sharedStart->copy()->addHours(2),
        ]);

        $newer = $this->createEvent([
            'title' => 'Same Start Newer',
            'date_time_start' => $sharedStart,
            'date_time_end' => $sharedStart->copy()->addHours(2),
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?page=1&page_size=100"
        );

        $response->assertStatus(200);
        $eventIds = collect($response->json('data'))
            ->pluck('event_id')
            ->values();
        $newerIndex = $eventIds->search((string) $newer->_id);
        $olderIndex = $eventIds->search((string) $older->_id);

        $this->assertNotFalse($newerIndex);
        $this->assertNotFalse($olderIndex);
        $this->assertLessThan($olderIndex, $newerIndex);
    }

    public function test_event_index_orders_nearest_start_first_by_default(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $baseStart = Carbon::now()->startOfDay()->addDays(3)->setHour(20);

        $this->createEvent([
            'title' => 'Farther Start Event',
            'date_time_start' => $baseStart->copy()->addDays(2),
            'date_time_end' => $baseStart->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Nearest Start Event',
            'date_time_start' => $baseStart,
            'date_time_end' => $baseStart->copy()->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Middle Start Event',
            'date_time_start' => $baseStart->copy()->addDay(),
            'date_time_end' => $baseStart->copy()->addDay()->addHours(2),
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?page=1&page_size=100"
        );

        $response->assertStatus(200);

        $orderedTitles = collect($response->json('data'))
            ->pluck('title')
            ->filter(static fn ($title): bool => in_array($title, [
                'Nearest Start Event',
                'Middle Start Event',
                'Farther Start Event',
            ], true))
            ->values()
            ->all();

        $this->assertSame([
            'Nearest Start Event',
            'Middle Start Event',
            'Farther Start Event',
        ], $orderedTitles);
    }

    public function test_public_event_index_keeps_latest_start_first_by_default(): void
    {
        $baseStart = Carbon::now()->startOfDay()->addDays(3)->setHour(20);

        $this->createEvent([
            'title' => 'Farther Public Event',
            'date_time_start' => $baseStart->copy()->addDays(2),
            'date_time_end' => $baseStart->copy()->addDays(2)->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Nearest Public Event',
            'date_time_start' => $baseStart,
            'date_time_end' => $baseStart->copy()->addHours(2),
        ]);

        $this->createEvent([
            'title' => 'Middle Public Event',
            'date_time_start' => $baseStart->copy()->addDay(),
            'date_time_end' => $baseStart->copy()->addDay()->addHours(2),
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}events?page=1&page_size=".InputConstraints::PUBLIC_PAGE_SIZE_MAX
        );

        $response->assertStatus(200);

        $orderedTitles = collect($response->json('data'))
            ->pluck('title')
            ->filter(static fn ($title): bool => in_array($title, [
                'Farther Public Event',
                'Middle Public Event',
                'Nearest Public Event',
            ], true))
            ->values()
            ->all();

        $this->assertSame([
            'Farther Public Event',
            'Middle Public Event',
            'Nearest Public Event',
        ], $orderedTitles);
    }

    public function test_event_index_composes_specific_date_temporal_and_profile_filters(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $targetDate = Carbon::now()->startOfDay()->addDays(5)->setHour(21);
        $secondaryVenue = $this->createAccountProfile('venue', 'Secondary Venue');

        $this->createEvent([
            'title' => 'Composed Date Filter Match',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
            'event_parties' => [
                [
                    'party_type' => (string) $this->band->profile_type,
                    'party_ref_id' => (string) $this->band->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->band->display_name,
                        'slug' => (string) $this->band->slug,
                        'profile_type' => (string) $this->band->profile_type,
                    ],
                ],
            ],
            'date_time_start' => $targetDate,
            'date_time_end' => $targetDate->copy()->addHours(3),
        ]);

        $this->createEvent([
            'title' => 'Composed Date Wrong Day Miss',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
            'event_parties' => [
                [
                    'party_type' => (string) $this->band->profile_type,
                    'party_ref_id' => (string) $this->band->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->band->display_name,
                        'slug' => (string) $this->band->slug,
                        'profile_type' => (string) $this->band->profile_type,
                    ],
                ],
            ],
            'date_time_start' => $targetDate->copy()->addDay(),
            'date_time_end' => $targetDate->copy()->addDay()->addHours(3),
        ]);

        $this->createEvent([
            'title' => 'Composed Date Wrong Related Miss',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
            'date_time_start' => $targetDate,
            'date_time_end' => $targetDate->copy()->addHours(3),
        ]);

        $this->createEvent([
            'title' => 'Composed Date Wrong Venue Miss',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $secondaryVenue->_id,
            ],
            'event_parties' => [
                [
                    'party_type' => (string) $this->band->profile_type,
                    'party_ref_id' => (string) $this->band->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->band->display_name,
                        'slug' => (string) $this->band->slug,
                        'profile_type' => (string) $this->band->profile_type,
                    ],
                ],
            ],
            'date_time_start' => $targetDate,
            'date_time_end' => $targetDate->copy()->addHours(3),
        ]);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?date={$targetDate->toDateString()}&temporal=future&venue_profile_id={$this->venue->_id}&related_account_profile_id={$this->band->_id}"
        );

        $response->assertStatus(200);
        $this->assertSame(
            ['Composed Date Filter Match'],
            collect($response->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_event_account_profile_candidates_endpoint_allows_read_create_or_update_ability_and_returns_filtered_candidates(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);
        $venueSearch = (string) $this->venue->slug;
        $partialVenueSearch = substr($venueSearch, -6, 5);

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search={$venueSearch}&page=1&page_size=20");

        $response->assertStatus(200);
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('per_page', 20);

        $venues = collect($response->json('data') ?? []);
        $matchedVenue = $venues->firstWhere('id', (string) $this->venue->_id);

        $this->assertNotNull($matchedVenue);
        $this->assertSame('venue', (string) ($matchedVenue['profile_type'] ?? ''));

        $partialResponse = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search={$partialVenueSearch}&page=1&page_size=20");
        $partialResponse->assertStatus(200);
        $partialVenues = collect($partialResponse->json('data') ?? []);
        $this->assertNotNull($partialVenues->firstWhere('id', (string) $this->venue->_id));

        Sanctum::actingAs($landlord, ['events:create']);
        $createResponse = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search={$venueSearch}");
        $createResponse->assertStatus(200);

        Sanctum::actingAs($landlord, ['events:update']);
        $updateResponse = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search={$venueSearch}");
        $updateResponse->assertStatus(200);
    }

    public function test_event_account_profile_candidates_endpoint_paginates_related_account_profiles_beyond_one_hundred_results(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        foreach (range(1, 102) as $index) {
            $this->createAccountProfile(
                'band',
                sprintf('Zulu Collective %03d', $index)
            );
        }

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=related_account_profile&search=zulu&page=6&page_size=20");

        $response->assertStatus(200);
        $response->assertJsonPath('current_page', 6);
        $response->assertJsonPath('per_page', 20);
        $response->assertJsonPath('total', 102);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.display_name', 'Zulu Collective 101');
        $response->assertJsonPath('data.1.display_name', 'Zulu Collective 102');
    }

    public function test_event_account_profile_candidates_endpoint_excludes_canonical_venue_profiles_from_related_results(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $venue = $this->createAccountProfile('venue', 'Selector Shared Venue');
        $artist = $this->createAccountProfile('artist', 'Selector Shared Artist');

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}/account_profile_candidates?type=related_account_profile&search=selector%20shared"
        );

        $response->assertStatus(200);

        $candidates = collect($response->json('data') ?? []);
        $candidateIds = $candidates->pluck('id')->values()->all();

        $this->assertContains((string) $artist->_id, $candidateIds);
        $this->assertNotContains((string) $venue->_id, $candidateIds);
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

        $response = $this->getJson("{$this->tenantAdminEventsBase}/account_profile_candidates?type=related_account_profile");

        $response->assertStatus(403);
    }

    public function test_account_events_account_profile_candidates_endpoint_uses_account_auth_boundary(): void
    {
        $foreignVenueIds = collect();
        foreach (range(1, 20) as $index) {
            $foreignVenueIds->push((string) $this->createAccountProfile(
                'venue',
                sprintf('Main Decoy Venue %03d', $index)
            )->_id);
        }

        Sanctum::actingAs($this->user, ['events:create']);

        $response = $this->getJson("{$this->accountEventsBase}/account_profile_candidates?type=physical_host&search=main");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');

        $venues = collect($response->json('data') ?? []);
        $matchedVenue = $venues->firstWhere('id', (string) $this->venue->_id);
        $this->assertNotNull($matchedVenue);
        $this->assertSame((string) $this->account->_id, data_get($matchedVenue, 'account_id'));
        $this->assertSame([], $venues->pluck('id')->intersect($foreignVenueIds)->values()->all());
    }

    public function test_account_events_account_profile_candidates_endpoint_paginates_related_account_profiles_beyond_one_hundred_results(): void
    {
        Sanctum::actingAs($this->user, ['events:create']);

        foreach (range(1, 102) as $index) {
            $this->createAccountProfile(
                'band',
                sprintf('Scoped Zulu Collective %03d', $index)
            );
        }

        $response = $this->getJson("{$this->accountEventsBase}/account_profile_candidates?type=related_account_profile&search=scoped%20zulu&page=6&page_size=20");

        $response->assertStatus(200);
        $response->assertJsonPath('current_page', 6);
        $response->assertJsonPath('per_page', 20);
        $response->assertJsonPath('total', 102);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.display_name', 'Scoped Zulu Collective 101');
        $response->assertJsonPath('data.1.display_name', 'Scoped Zulu Collective 102');
    }

    public function test_event_create_persists_created_by_and_canonical_event_parties(): void
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
        $response->assertJsonPath('data.venue.slug', $venueSlug);
        $response->assertJsonPath('data.linked_account_profiles.0.slug', $artistSlug);

        $parties = collect($response->json('data.event_parties') ?? []);
        $artistParty = $parties->firstWhere('party_type', 'artist');

        $this->assertNotNull($artistParty);
        $this->assertCount(1, $parties);
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

    public function test_tenant_admin_legacy_event_parties_summary_counts_invalid_without_mutation(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacy = $this->createEvent([
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
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->venue->display_name,
                        'slug' => (string) $this->venue->slug,
                        'profile_type' => (string) $this->venue->profile_type,
                        'avatar_url' => $this->venue->avatar_url,
                        'cover_url' => $this->venue->cover_url,
                        'taxonomy_terms' => is_array($this->venue->taxonomy_terms ?? null)
                            ? $this->venue->taxonomy_terms
                            : [],
                    ],
                ],
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => 'DJ Test',
                        'profile_type' => 'artist',
                    ],
                ],
            ],
        ]);
        $canonical = $this->createEvent();

        $beforeParties = $legacy->fresh()->event_parties;
        $beforeArtists = $legacy->fresh()->artists;

        $response = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");

        $response->assertStatus(200);
        $response->assertJsonPath('data.scanned', 2);
        $response->assertJsonPath('data.invalid', 1);
        $response->assertJsonPath('data.repaired', 0);
        $response->assertJsonPath('data.failed', 0);
        $response->assertJsonPath('data.unchanged', 1);

        $this->assertSame($beforeParties, $legacy->fresh()->event_parties);
        $this->assertSame($beforeArtists, $legacy->fresh()->artists);
        $this->assertSame((string) $canonical->_id, (string) $canonical->fresh()->_id);
    }

    public function test_tenant_admin_legacy_event_parties_repair_is_safe_and_idempotent(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacy = $this->createEvent([
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
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => 'DJ Test',
                        'profile_type' => 'artist',
                    ],
                ],
            ],
        ]);
        $canonical = $this->createEvent();

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");

        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 2);
        $repair->assertJsonPath('data.invalid', 1);
        $repair->assertJsonPath('data.repaired', 1);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 1);

        $legacy = $legacy->fresh();
        $this->assertCount(1, $legacy->event_parties);
        $this->assertSame('artist', data_get($legacy->event_parties, '0.party_type'));
        $this->assertSame((string) $this->artist->_id, data_get($legacy->event_parties, '0.party_ref_id'));
        $this->assertFalse((bool) data_get($legacy->event_parties, '0.permissions.can_edit'));
        $this->assertSame((string) $this->artist->slug, data_get($legacy->event_parties, '0.metadata.slug'));
        $this->assertSame('artist', data_get($legacy->event_parties, '0.metadata.profile_type'));
        $this->assertNull($legacy->artists);

        $syncedOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $legacy->_id)
            ->where('occurrence_index', 0)
            ->first();
        $this->assertNotNull($syncedOccurrence);
        $this->assertCount(1, $syncedOccurrence->event_parties ?? []);
        $this->assertSame('artist', data_get($syncedOccurrence->event_parties, '0.party_type'));
        $this->assertSame((string) $this->artist->slug, data_get($syncedOccurrence->event_parties, '0.metadata.slug'));
        $this->assertSame('DJ Test', data_get($syncedOccurrence->artists, '0.display_name'));
        $this->assertSame((string) $this->artist->slug, data_get($syncedOccurrence->artists, '0.slug'));

        $canonicalAfter = $canonical->fresh();
        $this->assertCount(1, $canonicalAfter->event_parties);
        $this->assertSame((string) $this->artist->_id, data_get($canonicalAfter->event_parties, '0.party_ref_id'));

        $secondRun = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");
        $secondRun->assertStatus(200);
        $secondRun->assertJsonPath('data.scanned', 2);
        $secondRun->assertJsonPath('data.invalid', 0);
        $secondRun->assertJsonPath('data.repaired', 0);
        $secondRun->assertJsonPath('data.failed', 0);
        $secondRun->assertJsonPath('data.unchanged', 2);
    }

    public function test_event_create_via_api_remains_valid_in_legacy_event_parties_summary(): void
    {
        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(201);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $summary = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.scanned', 1);
        $summary->assertJsonPath('data.invalid', 0);
        $summary->assertJsonPath('data.repaired', 0);
        $summary->assertJsonPath('data.failed', 0);
        $summary->assertJsonPath('data.unchanged', 1);
    }

    public function test_event_update_via_api_remains_valid_in_legacy_event_parties_summary(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $updated = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'Updated Yet Canonical',
        ]);
        $updated->assertStatus(200);
        $updated->assertJsonPath('data.title', 'Updated Yet Canonical');

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $summary = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.scanned', 1);
        $summary->assertJsonPath('data.invalid', 0);
        $summary->assertJsonPath('data.repaired', 0);
        $summary->assertJsonPath('data.failed', 0);
        $summary->assertJsonPath('data.unchanged', 1);
    }

    public function test_event_update_without_event_parties_preserves_invalid_legacy_rows_until_explicit_repair(): void
    {
        $legacy = $this->createEvent([
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
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => $this->artist->display_name,
                        'slug' => (string) $this->artist->slug,
                        'profile_type' => (string) $this->artist->profile_type,
                    ],
                ],
            ],
        ]);

        $beforeParties = $legacy->event_parties;

        $updated = $this->patchJson("{$this->accountEventsBase}/{$legacy->_id}", [
            'title' => 'Updated But Still Legacy',
        ]);

        $updated->assertStatus(200);
        $updated->assertJsonPath('data.title', 'Updated But Still Legacy');
        $updated->assertJsonCount(2, 'data.event_parties');
        $updated->assertJsonPath('data.event_parties.0.party_type', 'venue');

        $legacy = $legacy->fresh();
        $this->assertSame($beforeParties, $legacy->event_parties);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $summary = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.scanned', 1);
        $summary->assertJsonPath('data.invalid', 1);
        $summary->assertJsonPath('data.repaired', 0);
        $summary->assertJsonPath('data.failed', 0);
        $summary->assertJsonPath('data.unchanged', 0);
    }

    public function test_event_without_related_accounts_remains_valid_in_legacy_event_parties_summary_and_repair(): void
    {
        $event = $this->createEvent([
            'event_parties' => [],
            'artists' => null,
        ]);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $summary = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.scanned', 1);
        $summary->assertJsonPath('data.invalid', 0);
        $summary->assertJsonPath('data.repaired', 0);
        $summary->assertJsonPath('data.failed', 0);
        $summary->assertJsonPath('data.unchanged', 1);

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");
        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 1);
        $repair->assertJsonPath('data.invalid', 0);
        $repair->assertJsonPath('data.repaired', 0);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 1);

        $fresh = $event->fresh();
        $this->assertSame([], $fresh->event_parties ?? []);
        $this->assertNull($fresh->artists);
    }

    public function test_tenant_admin_legacy_event_parties_repair_restores_non_artist_related_profile_metadata_without_hardcoded_artist_dependency(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacy = $this->createEvent([
            'artists' => null,
            'event_parties' => [
                [
                    'party_type' => 'band',
                    'party_ref_id' => (string) $this->band->_id,
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => $this->band->display_name,
                    ],
                ],
            ],
        ]);

        $summary = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.scanned', 1);
        $summary->assertJsonPath('data.invalid', 1);
        $summary->assertJsonPath('data.repaired', 0);
        $summary->assertJsonPath('data.failed', 0);
        $summary->assertJsonPath('data.unchanged', 0);

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");
        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 1);
        $repair->assertJsonPath('data.invalid', 1);
        $repair->assertJsonPath('data.repaired', 1);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 0);

        $legacy = $legacy->fresh();
        $this->assertCount(1, $legacy->event_parties ?? []);
        $this->assertSame('band', data_get($legacy->event_parties, '0.party_type'));
        $this->assertSame((string) $this->band->_id, data_get($legacy->event_parties, '0.party_ref_id'));
        $this->assertFalse((bool) data_get($legacy->event_parties, '0.permissions.can_edit'));
        $this->assertSame((string) $this->band->slug, data_get($legacy->event_parties, '0.metadata.slug'));
        $this->assertSame('band', data_get($legacy->event_parties, '0.metadata.profile_type'));
        $this->assertSame($this->band->display_name, data_get($legacy->event_parties, '0.metadata.display_name'));
        $this->assertNull($legacy->artists);
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
        $response->assertJsonPath('data.taxonomy_terms.0.name', 'Showcase');
        $response->assertJsonPath('data.taxonomy_terms.0.taxonomy_name', 'Event Style');
        $response->assertJsonPath('data.taxonomy_terms.0.label', 'Showcase');

        $eventId = (string) $response->json('data.event_id');
        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $this->assertSame('Showcase', data_get($event->taxonomy_terms, '0.name'));
        $this->assertSame('Event Style', data_get($occurrence->taxonomy_terms, '0.taxonomy_name'));
    }

    public function test_event_create_rejects_taxonomy_not_allowed_by_selected_event_type(): void
    {
        $audience = Taxonomy::create([
            'slug' => 'audience',
            'name' => 'Audience',
            'applies_to' => ['event'],
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) $audience->_id,
            'slug' => 'kids',
            'name' => 'Kids',
        ]);

        $payload = $this->makeEventPayload([
            'taxonomy_terms' => [
                ['type' => 'audience', 'value' => 'kids'],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['taxonomy_terms']);
    }

    public function test_event_update_rejects_taxonomy_not_allowed_by_existing_event_type(): void
    {
        $audience = Taxonomy::create([
            'slug' => 'audience',
            'name' => 'Audience',
            'applies_to' => ['event'],
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) $audience->_id,
            'slug' => 'kids',
            'name' => 'Kids',
        ]);

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $created->assertStatus(201);

        $response = $this->patchJson(
            "{$this->accountEventsBase}/{$created->json('data.event_id')}",
            [
                'taxonomy_terms' => [
                    ['type' => 'audience', 'value' => 'kids'],
                ],
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['taxonomy_terms']);
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

    public function test_event_create_rejects_legacy_artist_ids_payload(): void
    {
        $payload = $this->makeEventPayload([
            'artist_ids' => [(string) $this->venue->_id],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['artist_ids']);
    }

    public function test_event_create_rejects_client_supplied_event_party_type(): void
    {
        $payload = $this->makeEventPayload([
            'event_parties' => [
                [
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                    'party_type' => 'unknown_party',
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_parties.0.party_type']);
    }

    public function test_event_create_rejects_client_supplied_event_party_metadata(): void
    {
        $payload = $this->makeEventPayload([
            'event_parties' => [
                [
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->venue->display_name,
                        'slug' => (string) $this->venue->slug,
                        'profile_type' => (string) $this->venue->profile_type,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_parties.0.metadata']);
    }

    public function test_event_create_rejects_venue_account_profile_reference_in_event_parties(): void
    {
        $payload = $this->makeEventPayload([
            'event_parties' => [
                [
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_parties.0.party_ref_id']);
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

    public function test_event_index_filters_by_temporal_buckets(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $now = Carbon::now();

        $this->createEvent([
            'title' => 'Past Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->subDays(2),
            'date_time_end' => $now->copy()->subDay(),
        ]);

        $this->createEvent([
            'title' => 'Live Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->subHour(),
            'date_time_end' => $now->copy()->addHour(),
        ]);

        $this->createEvent([
            'title' => 'Future Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->addHours(3),
            'date_time_end' => $now->copy()->addHours(5),
        ]);

        $response = $this->getJson("{$this->tenantAdminEventsBase}?temporal=now,future");

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['Live Event', 'Future Event'],
            collect($response->json('data'))->pluck('title')->all()
        );

        $pastResponse = $this->getJson("{$this->tenantAdminEventsBase}?temporal=past");

        $pastResponse->assertStatus(200);
        $this->assertSame(
            ['Past Event'],
            collect($pastResponse->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_event_index_future_filter_keeps_event_visible_when_later_occurrence_is_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-01T12:00:00Z'));

        $occurrences = [
            [
                'date_time_start' => Carbon::now()->subDays(2)->setHour(20)->toISOString(),
                'date_time_end' => Carbon::now()->subDays(2)->setHour(22)->toISOString(),
            ],
            [
                'date_time_start' => Carbon::now()->addDays(2)->setHour(20)->toISOString(),
                'date_time_end' => Carbon::now()->addDays(2)->setHour(22)->toISOString(),
            ],
        ];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Future Later Occurrence',
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $response = $this->getJson("{$this->tenantAdminEventsBase}?temporal=future");

        $response->assertStatus(200);
        $this->assertContains(
            'Future Later Occurrence',
            collect($response->json('data'))->pluck('title')->values()->all()
        );
        $this->assertCount(2, $response->json('data.0.occurrences'));

        Carbon::setTestNow();
    }

    public function test_event_index_temporal_filter_uses_default_duration_when_end_is_missing(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        Carbon::setTestNow(Carbon::parse('2026-05-01T12:00:00Z'));
        $now = Carbon::now();

        $this->createEvent([
            'title' => 'Past Null-End Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->subHours(5),
            'date_time_end' => null,
        ]);

        $this->createEvent([
            'title' => 'Live Null-End Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->subHour(),
            'date_time_end' => null,
        ]);

        $this->createEvent([
            'title' => 'Future Null-End Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->addHours(2),
            'date_time_end' => null,
        ]);

        $nowResponse = $this->getJson("{$this->tenantAdminEventsBase}?temporal=now");
        $nowResponse->assertStatus(200);
        $this->assertSame(
            ['Live Null-End Event'],
            collect($nowResponse->json('data'))->pluck('title')->values()->all()
        );

        $pastResponse = $this->getJson("{$this->tenantAdminEventsBase}?temporal=past");
        $pastResponse->assertStatus(200);
        $this->assertSame(
            ['Past Null-End Event'],
            collect($pastResponse->json('data'))->pluck('title')->values()->all()
        );

        $futureResponse = $this->getJson("{$this->tenantAdminEventsBase}?temporal=future");
        $futureResponse->assertStatus(200);
        $this->assertSame(
            ['Future Null-End Event'],
            collect($futureResponse->json('data'))->pluck('title')->values()->all()
        );

        Carbon::setTestNow();
    }

    public function test_event_index_temporal_filter_is_independent_from_archived_dimension(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $now = Carbon::now();

        $archivedPast = $this->createEvent([
            'title' => 'Archived Past Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->subDays(2),
            'date_time_end' => $now->copy()->subDay(),
        ]);
        $archivedPast->delete();

        $archivedFuture = $this->createEvent([
            'title' => 'Archived Future Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subDay(),
            ],
            'date_time_start' => $now->copy()->addDay(),
            'date_time_end' => $now->copy()->addDays(2),
        ]);
        $archivedFuture->delete();

        $response = $this->getJson("{$this->tenantAdminEventsBase}?archived=1&temporal=future");

        $response->assertStatus(200);
        $this->assertSame(
            ['Archived Future Event'],
            collect($response->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_tenant_admin_archived_events_list_normalizes_legacy_place_ref_id(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $draftArchived = $this->createEvent([
            'title' => 'Draft Archived Event',
            'publication' => ['status' => 'draft'],
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $this->venue->_id,
            ],
        ]);
        $draftArchived->delete();

        $publishedArchived = $this->createEvent([
            'title' => 'Published Archived Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute(),
            ],
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $this->venue->_id,
            ],
        ]);
        $publishedArchived->delete();

        $this->createEvent([
            'title' => 'Active Draft Event',
            'publication' => ['status' => 'draft'],
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $this->venue->_id,
            ],
        ]);

        $response = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");

        $response->assertStatus(200);
        $items = collect($response->json('data'));

        $draftItem = $items->firstWhere('title', 'Draft Archived Event');
        $publishedItem = $items->firstWhere('title', 'Published Archived Event');

        $this->assertIsArray($draftItem);
        $this->assertIsArray($publishedItem);
        $this->assertNull($items->firstWhere('title', 'Active Draft Event'));

        $this->assertSame((string) $this->venue->_id, data_get($draftItem, 'place_ref.id'));
        $this->assertSame('account_profile', data_get($draftItem, 'place_ref.type'));
        $this->assertSame((string) $this->venue->_id, data_get($publishedItem, 'place_ref.id'));
        $this->assertSame('account_profile', data_get($publishedItem, 'place_ref.type'));
    }

    public function test_tenant_admin_archived_events_list_normalizes_legacy_wrapped_date_leaves(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $archived = $this->createEvent([
            'title' => 'Archived Legacy Dates Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::parse('2026-03-01T09:59:00+00:00'),
            ],
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => 'https://cdn.example.com/thumb.png',
                ],
            ],
            'occurrences' => [[
                'date_time_start' => '2026-03-01T11:00:00+00:00',
                'date_time_end' => '2026-03-01T12:00:00+00:00',
            ]],
        ]);
        $archived->delete();

        Event::withTrashed()
            ->where('_id', $archived->getKey())
            ->update([
                'publication' => [
                    'status' => 'published',
                    'publish_at' => [
                        '$date' => '2026-03-01T09:59:00.000Z',
                    ],
                ],
                'date_time_start' => [
                    '$date' => '2026-03-01T11:00:00.000Z',
                ],
                'date_time_end' => [
                    '$date' => '2026-03-01T12:00:00.000Z',
                ],
            ]);

        $response = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");

        $response->assertStatus(200);
        $item = collect($response->json('data'))->firstWhere('title', 'Archived Legacy Dates Event');
        $this->assertIsArray($item);
        $this->assertSame('2026-03-01T09:59:00+00:00', data_get($item, 'publication.publish_at'));
        $this->assertSame('2026-03-01T11:00:00+00:00', data_get($item, 'date_time_start'));
        $this->assertSame('2026-03-01T12:00:00+00:00', data_get($item, 'date_time_end'));
        $this->assertSame('https://cdn.example.com/thumb.png', data_get($item, 'thumb.data.url'));
    }

    public function test_tenant_admin_archived_events_list_accepts_real_tenant_guarappari_fixture_shape(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $rows = $this->loadEventFixtureRows('tenant_guarappari.events.json');
        $archivedRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['deleted_at'] ?? null) !== null
        ));

        foreach ($archivedRows as $row) {
            $this->insertLegacyArchivedEventFixture($row);
        }

        $response = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(count($archivedRows), $items);

        $dbg = $items->firstWhere('slug', 'dbg');
        $this->assertIsArray($dbg);
        $this->assertSame('1', data_get($dbg, 'type.id'));
        $this->assertNull(data_get($dbg, 'thumb'));
        $this->assertNull(data_get($dbg, 'thumb.data.url'));
        $this->assertIsString(data_get($dbg, 'date_time_start'));
        $this->assertIsString(data_get($dbg, 'date_time_end'));
        $this->assertIsString(data_get($dbg, 'publication.publish_at'));
    }

    public function test_tenant_admin_legacy_event_parties_repair_supports_legacy_artist_underscore_id_shape(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacy = $this->createEvent([
            'artists' => [
                [
                    '_id' => (string) $this->artist->_id,
                    'display_name' => $this->artist->display_name,
                    'avatar_url' => null,
                    'highlight' => false,
                    'genres' => ['rock'],
                    'taxonomy_terms' => [
                        ['type' => 'music_genre', 'value' => 'rock'],
                    ],
                ],
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
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => 'DJ Test',
                        'profile_type' => 'artist',
                    ],
                ],
            ],
        ]);

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");

        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 1);
        $repair->assertJsonPath('data.invalid', 1);
        $repair->assertJsonPath('data.repaired', 1);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 0);

        $legacy = $legacy->fresh();
        $this->assertCount(1, $legacy->event_parties);
        $this->assertSame('artist', data_get($legacy->event_parties, '0.party_type'));
        $this->assertSame((string) $this->artist->_id, data_get($legacy->event_parties, '0.party_ref_id'));
        $this->assertSame((string) $this->artist->slug, data_get($legacy->event_parties, '0.metadata.slug'));
        $this->assertNull($legacy->artists);
    }

    public function test_tenant_admin_legacy_event_parties_summary_scans_archived_invalid_events(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacyArchived = $this->createEvent([
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
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
            ],
        ]);
        $legacyArchived->delete();
        $this->createEvent();

        $response = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");

        $response->assertStatus(200);
        $response->assertJsonPath('data.scanned', 2);
        $response->assertJsonPath('data.invalid', 1);
        $response->assertJsonPath('data.repaired', 0);
        $response->assertJsonPath('data.failed', 0);
        $response->assertJsonPath('data.unchanged', 1);
    }

    public function test_tenant_admin_legacy_event_parties_repair_repairs_archived_events_and_keeps_occurrences_archived(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacyArchived = $this->createEvent([
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
            'event_parties' => [
                [
                    'party_type' => 'venue',
                    'party_ref_id' => (string) $this->venue->_id,
                    'permissions' => ['can_edit' => true],
                ],
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => 'DJ Test',
                        'profile_type' => 'artist',
                    ],
                ],
            ],
        ]);
        $legacyArchivedId = (string) $legacyArchived->_id;
        $legacyArchived->delete();

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");

        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 1);
        $repair->assertJsonPath('data.invalid', 1);
        $repair->assertJsonPath('data.repaired', 1);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 0);

        $legacyArchived = Event::withTrashed()->findOrFail($legacyArchivedId);
        $this->assertNotNull($legacyArchived->deleted_at);
        $this->assertCount(1, $legacyArchived->event_parties);
        $this->assertSame('artist', data_get($legacyArchived->event_parties, '0.party_type'));
        $this->assertSame((string) $this->artist->slug, data_get($legacyArchived->event_parties, '0.metadata.slug'));
        $this->assertNull($legacyArchived->artists);

        $occurrence = EventOccurrence::withTrashed()
            ->where('event_id', $legacyArchivedId)
            ->where('occurrence_index', 0)
            ->first();
        $this->assertNotNull($occurrence);
        $this->assertNotNull($occurrence->deleted_at);
        $this->assertCount(1, $occurrence->event_parties ?? []);
        $this->assertSame('artist', data_get($occurrence->event_parties, '0.party_type'));
        $this->assertSame((string) $this->artist->slug, data_get($occurrence->event_parties, '0.metadata.slug'));
        $this->assertSame('DJ Test', data_get($occurrence->artists, '0.display_name'));
        $this->assertSame((string) $this->artist->slug, data_get($occurrence->artists, '0.slug'));
    }

    public function test_tenant_admin_legacy_event_parties_summary_counts_archived_admin_payload_invalid_events(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacyArchived = $this->createEvent([
            'title' => 'Archived Broken Thumb Event',
            'type' => [
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
                '_id' => (string) $this->eventType->_id,
            ],
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $this->venue->_id,
                'metadata' => [
                    'display_name' => $this->venue->display_name,
                ],
            ],
            'venue' => [
                '_id' => (string) $this->venue->_id,
                'display_name' => $this->venue->display_name,
                'taxonomy_terms' => [],
            ],
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => ['broken' => true],
                ],
            ],
        ]);
        $legacyArchived->delete();

        $response = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");

        $response->assertStatus(200);
        $response->assertJsonPath('data.scanned', 1);
        $response->assertJsonPath('data.invalid', 1);
        $response->assertJsonPath('data.repaired', 0);
        $response->assertJsonPath('data.failed', 0);
        $response->assertJsonPath('data.unchanged', 0);
    }

    public function test_tenant_admin_legacy_event_parties_repair_sanitizes_archived_admin_payload_invalid_events(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacyArchived = $this->createEvent([
            'title' => 'Archived Broken Thumb Event',
            'type' => [
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
                '_id' => (string) $this->eventType->_id,
            ],
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $this->venue->_id,
                'metadata' => [
                    'display_name' => $this->venue->display_name,
                ],
            ],
            'venue' => [
                '_id' => (string) $this->venue->_id,
                'display_name' => $this->venue->display_name,
                'taxonomy_terms' => [],
            ],
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => ['broken' => true],
                ],
            ],
        ]);
        $legacyArchivedId = (string) $legacyArchived->_id;
        $legacyArchived->delete();

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");

        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 1);
        $repair->assertJsonPath('data.invalid', 1);
        $repair->assertJsonPath('data.repaired', 1);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 0);

        $repaired = Event::withTrashed()->findOrFail($legacyArchivedId);
        $this->assertSame((string) $this->eventType->_id, data_get($repaired->type, 'id'));
        $this->assertSame((string) $this->venue->_id, data_get($repaired->place_ref, 'id'));
        $this->assertSame((string) $this->venue->_id, data_get($repaired->venue, 'id'));
        $this->assertNull($repaired->thumb);

        $archivedList = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");
        $archivedList->assertStatus(200);
        $item = collect($archivedList->json('data'))->firstWhere('title', 'Archived Broken Thumb Event');
        $this->assertIsArray($item);
        $this->assertSame((string) $this->eventType->_id, data_get($item, 'type.id'));
        $this->assertSame((string) $this->venue->_id, data_get($item, 'place_ref.id'));
        $this->assertNull(data_get($item, 'thumb'));
    }

    public function test_tenant_admin_legacy_event_parties_repair_sanitizes_archived_admin_payload_invalid_thumb_url_string(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read', 'events:update']);

        $legacyArchived = $this->createEvent([
            'title' => 'Archived Invalid Thumb Url Event',
            'type' => [
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
                '_id' => (string) $this->eventType->_id,
            ],
            'place_ref' => [
                'type' => 'account_profile',
                '_id' => (string) $this->venue->_id,
                'metadata' => [
                    'display_name' => $this->venue->display_name,
                ],
            ],
            'venue' => [
                '_id' => (string) $this->venue->_id,
                'display_name' => $this->venue->display_name,
                'taxonomy_terms' => [],
            ],
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => 'u',
                ],
            ],
        ]);
        $legacyArchivedId = (string) $legacyArchived->_id;
        $legacyArchived->delete();

        $summary = $this->getJson("{$this->tenantAdminEventsBase}/legacy_event_parties/summary");
        $summary->assertStatus(200);
        $summary->assertJsonPath('data.scanned', 1);
        $summary->assertJsonPath('data.invalid', 1);

        $repair = $this->postJson("{$this->tenantAdminEventsBase}/legacy_event_parties/repair");
        $repair->assertStatus(200);
        $repair->assertJsonPath('data.scanned', 1);
        $repair->assertJsonPath('data.invalid', 1);
        $repair->assertJsonPath('data.repaired', 1);
        $repair->assertJsonPath('data.failed', 0);
        $repair->assertJsonPath('data.unchanged', 0);

        $repaired = Event::withTrashed()->findOrFail($legacyArchivedId);
        $this->assertNull($repaired->thumb);

        $archivedList = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");
        $archivedList->assertStatus(200);
        $item = collect($archivedList->json('data'))->firstWhere('title', 'Archived Invalid Thumb Url Event');
        $this->assertIsArray($item);
        $this->assertNull(data_get($item, 'thumb'));
    }

    public function test_tenant_admin_events_list_matrix_distinguishes_active_and_archived_status_filters(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $activeDraft = $this->createEvent([
            'title' => 'Active Draft Event',
            'publication' => ['status' => 'draft'],
        ]);

        $archivedDraft = $this->createEvent([
            'title' => 'Archived Draft Event',
            'publication' => ['status' => 'draft'],
        ]);
        $archivedDraft->delete();

        $archivedPublished = $this->createEvent([
            'title' => 'Archived Published Event',
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute(),
            ],
        ]);
        $archivedPublished->delete();

        $activeDraftResponse = $this->getJson("{$this->tenantAdminEventsBase}?status=draft");
        $activeDraftResponse->assertStatus(200);
        $this->assertSame(
            ['Active Draft Event'],
            collect($activeDraftResponse->json('data'))->pluck('title')->values()->all()
        );

        $archivedDraftResponse = $this->getJson("{$this->tenantAdminEventsBase}?status=draft&archived=1");
        $archivedDraftResponse->assertStatus(200);
        $this->assertSame(
            ['Archived Draft Event'],
            collect($archivedDraftResponse->json('data'))->pluck('title')->values()->all()
        );

        $allArchivedResponse = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");
        $allArchivedResponse->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['Archived Draft Event', 'Archived Published Event'],
            collect($allArchivedResponse->json('data'))->pluck('title')->all()
        );

        $archivedPublishedResponse = $this->getJson("{$this->tenantAdminEventsBase}?status=published&archived=1");
        $archivedPublishedResponse->assertStatus(200);
        $this->assertSame(
            ['Archived Published Event'],
            collect($archivedPublishedResponse->json('data'))->pluck('title')->values()->all()
        );
    }

    public function test_event_update_changes_fields(): void
    {
        $venueSlug = 'main-venue-updated-'.Str::lower(Str::random(6));
        $artistSlug = 'dj-test-updated-'.Str::lower(Str::random(6));

        $this->venue->slug = $venueSlug;
        $this->venue->save();

        $this->artist->slug = $artistSlug;
        $this->artist->save();

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
        $response->assertJsonPath('data.venue.slug', $venueSlug);
        $response->assertJsonPath('data.linked_account_profiles.0.slug', $artistSlug);
        $response->assertJsonCount(1, 'data.event_parties');
        $response->assertJsonPath('data.event_parties.0.metadata.slug', $artistSlug);
    }

    public function test_event_update_non_publication_fields_preserves_active_parent_occurrence_and_public_visibility(): void
    {
        $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Visible Before Update',
        ]));
        $createResponse->assertStatus(201);
        $eventId = (string) $createResponse->json('data.event_id');

        $beforeAgenda = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=20");
        $beforeAgenda->assertStatus(200);
        $beforeIds = collect($beforeAgenda->json('items'))->pluck('event_id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains($eventId, $beforeIds);

        $updateResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'Visible After Update',
        ]);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.title', 'Visible After Update');

        $event = Event::withTrashed()->findOrFail($eventId);
        $occurrence = EventOccurrence::withTrashed()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->first();
        $this->assertNotNull($occurrence);
        $this->assertNull($event->deleted_at);
        $this->assertNull($occurrence->deleted_at);
        $this->assertSame('published', data_get($event->publication, 'status'));
        $this->assertSame('published', data_get($occurrence->publication, 'status'));
        $this->assertTrue((bool) ($occurrence->is_event_published ?? false));

        $afterAgenda = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=20");
        $afterAgenda->assertStatus(200);
        $afterIds = collect($afterAgenda->json('items'))->pluck('event_id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains($eventId, $afterIds);
    }

    public function test_event_update_published_to_draft_reconciles_occurrence_and_admin_public_visibility(): void
    {
        $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Transition To Draft',
        ]));
        $createResponse->assertStatus(201);
        $eventId = (string) $createResponse->json('data.event_id');

        $updateResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'publication' => ['status' => 'draft'],
        ]);
        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.publication.status', 'draft');

        $event = Event::withTrashed()->findOrFail($eventId);
        $occurrence = EventOccurrence::withTrashed()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->first();
        $this->assertNotNull($occurrence);
        $this->assertNull($event->deleted_at);
        $this->assertNull($occurrence->deleted_at);
        $this->assertSame('draft', data_get($event->publication, 'status'));
        $this->assertSame('draft', data_get($occurrence->publication, 'status'));
        $this->assertFalse((bool) ($occurrence->is_event_published ?? true));

        $agenda = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=20");
        $agenda->assertStatus(200);
        $agendaIds = collect($agenda->json('items'))->pluck('event_id')->map(fn ($id) => (string) $id)->all();
        $this->assertNotContains($eventId, $agendaIds);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $draftAdmin = $this->getJson("{$this->tenantAdminEventsBase}?status=draft");
        $draftAdmin->assertStatus(200);
        $draftIds = collect($draftAdmin->json('data'))->pluck('event_id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains($eventId, $draftIds);
    }

    public function test_event_update_replaces_related_account_parties_when_event_parties_is_present(): void
    {
        $secondArtist = $this->createAccountProfile('artist', 'DJ Two');
        $secondArtist->slug = 'dj-two-'.Str::lower(Str::random(6));
        $secondArtist->save();

        $event = $this->createEvent([
            'artists' => [
                [
                    'id' => (string) $this->artist->_id,
                    'display_name' => $this->artist->display_name,
                    'slug' => (string) $this->artist->slug,
                    'profile_type' => (string) $this->artist->profile_type,
                    'avatar_url' => null,
                    'cover_url' => null,
                    'highlight' => false,
                    'genres' => ['rock'],
                    'taxonomy_terms' => [],
                ],
                [
                    'id' => (string) $secondArtist->_id,
                    'display_name' => $secondArtist->display_name,
                    'slug' => (string) $secondArtist->slug,
                    'profile_type' => (string) $secondArtist->profile_type,
                    'avatar_url' => null,
                    'cover_url' => null,
                    'highlight' => false,
                    'genres' => ['house'],
                    'taxonomy_terms' => [],
                ],
            ],
            'event_parties' => [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => $this->artist->display_name,
                        'slug' => (string) $this->artist->slug,
                        'profile_type' => (string) $this->artist->profile_type,
                        'avatar_url' => null,
                        'cover_url' => null,
                        'taxonomy_terms' => [],
                    ],
                ],
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $secondArtist->_id,
                    'permissions' => ['can_edit' => false],
                    'metadata' => [
                        'display_name' => $secondArtist->display_name,
                        'slug' => (string) $secondArtist->slug,
                        'profile_type' => (string) $secondArtist->profile_type,
                        'avatar_url' => null,
                        'cover_url' => null,
                        'taxonomy_terms' => [],
                    ],
                ],
            ],
        ]);

        $response = $this->patchJson("{$this->accountEventsBase}/{$event->_id}", [
            'event_parties' => [
                [
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => true],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.event_parties');

        $parties = collect($response->json('data.event_parties'));
        $this->assertTrue(
            $parties->contains(fn (array $party): bool => ($party['party_ref_id'] ?? null) === (string) $this->artist->_id
                && (bool) data_get($party, 'permissions.can_edit') === true)
        );
        $this->assertFalse(
            $parties->contains(fn (array $party): bool => ($party['party_ref_id'] ?? null) === (string) $secondArtist->_id)
        );

        $fresh = $event->fresh();
        $this->assertCount(1, $fresh->event_parties ?? []);
    }

    public function test_event_update_recomputes_account_context_ids_when_related_parties_are_removed(): void
    {
        $event = $this->createEvent();
        $eventId = (string) $event->_id;
        $artistAccountId = (string) $this->artist->account_id;
        $artistAccount = Account::query()->findOrFail($artistAccountId);
        $artistRole = $artistAccount->roleTemplates()->create([
            'name' => 'Artist Events Reader',
            'permissions' => ['*'],
        ]);
        $artistUser = $this->userService->create($artistAccount, [
            'name' => 'Artist Events Reader',
            'email' => uniqid('artist-events-reader', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $artistRole->_id);

        $this->assertContains($artistAccountId, $event->account_context_ids ?? []);

        Sanctum::actingAs($artistUser, ['events:read']);
        $artistScopedListBefore = $this->getJson("{$this->base_api_tenant}accounts/{$artistAccount->slug}/events");
        $artistScopedListBefore->assertStatus(200);
        $this->assertContains(
            $eventId,
            collect($artistScopedListBefore->json('data') ?? [])
                ->pluck('event_id')
                ->map(static fn ($id): string => (string) $id)
                ->values()
                ->all()
        );

        Sanctum::actingAs($this->user, ['events:read', 'events:update']);
        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'event_parties' => [],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.event_parties');

        $fresh = Event::query()->findOrFail($eventId);
        $this->assertNotContains($artistAccountId, $fresh->account_context_ids ?? []);
        $this->assertContains((string) $this->account->_id, $fresh->account_context_ids ?? []);

        Sanctum::actingAs($artistUser, ['events:read']);
        $artistScopedListAfter = $this->getJson("{$this->base_api_tenant}accounts/{$artistAccount->slug}/events");
        $artistScopedListAfter->assertStatus(200);
        $this->assertNotContains(
            $eventId,
            collect($artistScopedListAfter->json('data') ?? [])
                ->pluck('event_id')
                ->map(static fn ($id): string => (string) $id)
                ->values()
                ->all()
        );
    }

    public function test_event_update_remove_cover_multipart_can_clear_related_account_parties_with_json_empty_array(): void
    {
        Storage::fake('public');

        $secondArtist = $this->createAccountProfile('artist', 'DJ Clear');
        $secondArtist->slug = 'dj-clear-'.Str::lower(Str::random(6));
        $secondArtist->save();

        $created = $this->post(
            $this->accountEventsBase,
            array_merge($this->makeEventPayload([
                'event_parties' => [
                    [
                        'party_ref_id' => (string) $this->artist->_id,
                        'permissions' => ['can_edit' => true],
                    ],
                    [
                        'party_ref_id' => (string) $secondArtist->_id,
                        'permissions' => ['can_edit' => false],
                    ],
                ],
            ]), [
                'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
            ]),
        );
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $response = $this->post("{$this->accountEventsBase}/{$eventId}", [
            '_method' => 'PATCH',
            'remove_cover' => '1',
            'event_parties' => '[]',
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.event_parties');
        $this->assertNull($response->json('data.thumb'));

        $fresh = Event::query()->findOrFail($eventId);
        $this->assertSame([], $fresh->event_parties ?? []);
    }

    public function test_event_update_multipart_preserves_occurrence_owned_profiles_and_programming(): void
    {
        Storage::fake('public');

        $occurrences = $this->makeOccurrences(2);
        $occurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
            'permissions' => ['can_edit' => false],
        ]];
        $occurrences[1]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $response = $this->post("{$this->accountEventsBase}/{$eventId}", [
            '_method' => 'PATCH',
            'title' => 'Atualizado com multipart',
            'occurrences' => $occurrences,
            'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Atualizado com multipart');
        $response->assertJsonPath('data.occurrences.1.own_linked_account_profiles.0.id', (string) $this->band->_id);
        $response->assertJsonPath('data.occurrences.1.programming_items.0.title', 'Show com a banda');
        $response->assertJsonPath('data.occurrences.1.programming_items.0.place_ref.id', (string) $this->venue->_id);

        $freshSecondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $this->assertSame((string) $this->band->_id, data_get($freshSecondOccurrence, 'own_event_parties.0.party_ref_id'));
        $this->assertSame('17:00', data_get($freshSecondOccurrence, 'programming_items.0.time'));
        $this->assertSame('Show com a banda', data_get($freshSecondOccurrence, 'programming_items.0.title'));
        $this->assertSame((string) $this->venue->_id, data_get($freshSecondOccurrence, 'programming_items.0.place_ref.id'));

        $reloaded = $this->getJson("{$this->accountEventsBase}/{$eventId}");
        $reloaded->assertStatus(200);
        $reloaded->assertJsonPath('data.occurrences.1.own_linked_account_profiles.0.id', (string) $this->band->_id);
        $reloaded->assertJsonPath('data.occurrences.1.programming_items.0.title', 'Show com a banda');
        $reloaded->assertJsonPath('data.occurrences.1.programming_items.0.place_ref.id', (string) $this->venue->_id);
    }

    public function test_management_show_preserves_single_occurrence_programming_and_occurrence_profiles(): void
    {
        $occurrences = $this->makeOccurrences(1);
        $occurrences[0]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
            'permissions' => ['can_edit' => false],
        ]];
        $occurrences[0]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $reloaded = $this->getJson("{$this->accountEventsBase}/{$eventId}");

        $reloaded->assertStatus(200);
        $reloaded->assertJsonPath('data.occurrences.0.own_linked_account_profiles.0.id', (string) $this->band->_id);
        $reloaded->assertJsonPath('data.occurrences.0.programming_items.0.title', 'Show com a banda');
        $reloaded->assertJsonPath('data.occurrences.0.programming_items.0.account_profile_ids.0', (string) $this->band->_id);
        $reloaded->assertJsonPath('data.occurrences.0.programming_items.0.place_ref.id', (string) $this->venue->_id);
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

    public function test_event_create_allows_multiple_occurrences_without_tenant_or_event_capability(): void
    {
        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.occurrences'));
        $this->assertArrayNotHasKey(
            'multiple_occurrences',
            $response->json('data.capabilities') ?? []
        );
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

    public function test_event_create_projects_map_poi_with_event_type_type_asset_visual(): void
    {
        $this->eventType->fill([
            'visual' => [
                'mode' => 'image',
                'image_source' => 'type_asset',
                'image_url' => 'https://tenant-zeta.test/api/v1/media/event-types/type-1/type_asset?v=5',
            ],
            'poi_visual' => [
                'mode' => 'image',
                'image_source' => 'type_asset',
                'image_url' => 'https://tenant-zeta.test/api/v1/media/event-types/type-1/type_asset?v=5',
            ],
            'type_asset_url' => 'https://tenant-zeta.test/api/v1/media/event-types/type-1/type_asset?v=5',
            'icon' => null,
            'color' => null,
            'icon_color' => null,
        ])->save();

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $response->assertStatus(201);

        $eventId = (string) $response->json('data.event_id');
        $event = Event::query()->find($eventId);
        $this->assertNotNull($event);
        $this->assertSame('image', data_get($event->type, 'visual.mode'));
        $this->assertSame('type_asset', data_get($event->type, 'visual.image_source'));
        $this->assertSame(
            'https://tenant-zeta.test/api/v1/media/event-types/type-1/type_asset?v=5',
            data_get($event->type, 'visual.image_url')
        );

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event);

        $projection = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', $eventId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame('image', data_get($projection->visual, 'mode'));
        $this->assertSame(
            'https://tenant-zeta.test/api/v1/media/event-types/type-1/type_asset?v=5',
            data_get($projection->visual, 'image_uri')
        );
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

    public function test_event_create_rejects_unbounded_map_poi_polygon_rings(): void
    {
        $this->patchEventsSettings([
            'capabilities.map_poi.available' => true,
        ])->assertStatus(200);

        $rings = [];
        for ($index = 0; $index <= InputConstraints::MAP_POI_POLYGON_RINGS_MAX; $index++) {
            $rings[] = [
                [-40.00, -20.00],
                [-39.90, -20.00],
                [-39.90, -19.90],
                [-40.00, -20.00],
            ];
        }

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                    'discovery_scope' => [
                        'type' => 'polygon',
                        'polygon' => [
                            'type' => 'Polygon',
                            'coordinates' => $rings,
                        ],
                    ],
                ],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'capabilities.map_poi.discovery_scope.polygon.coordinates',
        ]);
    }

    public function test_event_create_rejects_out_of_range_map_poi_polygon_coordinates(): void
    {
        $this->patchEventsSettings([
            'capabilities.map_poi.available' => true,
        ])->assertStatus(200);

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                    'discovery_scope' => [
                        'type' => 'polygon',
                        'polygon' => [
                            'type' => 'Polygon',
                            'coordinates' => [[
                                [-40.00, -20.00],
                                [-39.90, -20.00],
                                [181.00, -19.90],
                                [-40.00, -20.00],
                            ]],
                        ],
                    ],
                ],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'capabilities.map_poi.discovery_scope.polygon.coordinates.0.2.0',
        ]);
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

    public function test_refresh_expired_event_map_pois_job_deactivates_stale_event_projections_without_event_mutation(): void
    {
        $baseline = Carbon::parse('2026-03-01T10:00:00+00:00');
        Carbon::setTestNow($baseline);

        try {
            $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
                'occurrences' => [[
                    'date_time_start' => $baseline->copy()->addHour()->toISOString(),
                    'date_time_end' => $baseline->copy()->addHours(2)->toISOString(),
                ]],
            ]));
            $createResponse->assertStatus(201);

            $eventId = (string) $createResponse->json('data.event_id');
            $event = Event::query()->find($eventId);
            $this->assertNotNull($event);
            $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event);

            $activePoi = MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $eventId)
                ->first();

            $this->assertNotNull($activePoi);
            $this->assertTrue((bool) ($activePoi->is_active ?? false));

            Carbon::setTestNow($baseline->copy()->addHours(5));

            app()->call([new RefreshExpiredEventMapPoisJob, 'handle']);

            $stalePoi = MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $eventId)
                ->first();

            $this->assertNotNull($stalePoi);
            $this->assertFalse((bool) ($stalePoi->is_active ?? true));
            $this->assertSame([], $stalePoi->occurrence_facets ?? []);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cleanup_orphaned_map_pois_job_deletes_orphan_event_projections_even_when_inactive(): void
    {
        $baseline = Carbon::parse('2026-03-01T10:00:00+00:00');
        Carbon::setTestNow($baseline);

        try {
            $activeResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
                'occurrences' => [[
                    'date_time_start' => $baseline->copy()->addHour()->toISOString(),
                    'date_time_end' => $baseline->copy()->addHours(2)->toISOString(),
                ]],
            ]));
            $activeResponse->assertStatus(201);

            $activeEventId = (string) $activeResponse->json('data.event_id');
            $activeEvent = Event::query()->find($activeEventId);
            $this->assertNotNull($activeEvent);
            $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($activeEvent);

            $inactiveResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
                'occurrences' => [[
                    'date_time_start' => $baseline->copy()->addHour()->toISOString(),
                    'date_time_end' => $baseline->copy()->addHours(2)->toISOString(),
                ]],
            ]));
            $inactiveResponse->assertStatus(201);

            $inactiveEventId = (string) $inactiveResponse->json('data.event_id');
            $inactiveEvent = Event::query()->find($inactiveEventId);
            $this->assertNotNull($inactiveEvent);
            $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($inactiveEvent);

            $inactivePoi = MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $inactiveEventId)
                ->first();
            $this->assertNotNull($inactivePoi);
            $inactivePoi->forceFill(['is_active' => false]);
            $inactivePoi->save();

            Event::withTrashed()->where('_id', $activeEventId)->forceDelete();
            Event::withTrashed()->where('_id', $inactiveEventId)->forceDelete();

            app()->call([new CleanupOrphanedMapPoisJob(['event']), 'handle']);

            $this->assertFalse(
                MapPoi::query()
                    ->where('ref_type', 'event')
                    ->where('ref_id', $activeEventId)
                    ->exists()
            );
            $this->assertFalse(
                MapPoi::query()
                    ->where('ref_type', 'event')
                    ->where('ref_id', $inactiveEventId)
                    ->exists()
            );
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

    public function test_event_create_allows_multiple_occurrences_by_default(): void
    {
        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.occurrences'));
        $eventId = (string) $response->json('data.event_id');
        $this->assertSame(
            2,
            EventOccurrence::query()->where('event_id', $eventId)->count()
        );
    }

    public function test_event_create_persists_occurrence_owned_profiles_and_programming_location_profile(): void
    {
        $occurrences = $this->makeOccurrences(2);
        $occurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
            'permissions' => ['can_edit' => false],
        ]];
        $occurrences[1]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(201);
        $response->assertJsonPath('data.occurrences.1.has_location_override', false);
        $response->assertJsonPath('data.occurrences.1.own_linked_account_profiles.0.id', (string) $this->band->_id);
        $response->assertJsonPath('data.occurrences.1.programming_items.0.title', 'Show com a banda');
        $response->assertJsonPath('data.occurrences.1.programming_items.0.place_ref.id', (string) $this->venue->_id);
        $response->assertJsonPath('data.occurrences.1.programming_items.0.location_profile.id', (string) $this->venue->_id);
        $response->assertJsonPath('data.linked_account_profiles.1.id', (string) $this->band->_id);

        $eventId = (string) $response->json('data.event_id');
        $firstOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();
        $secondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $this->assertFalse((bool) ($firstOccurrence->has_location_override ?? false));
        $this->assertSame((string) $this->venue->_id, data_get($firstOccurrence, 'place_ref.id'));
        $this->assertSame([], $firstOccurrence->programming_items ?? []);

        $this->assertFalse((bool) ($secondOccurrence->has_location_override ?? false));
        $this->assertNull(data_get($secondOccurrence, 'location_override'));
        $this->assertSame('physical', data_get($secondOccurrence, 'location.mode'));
        $this->assertSame((string) $this->venue->_id, data_get($secondOccurrence, 'place_ref.id'));
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'own_event_parties.0.party_ref_id'));
        $this->assertSame((string) $this->artist->_id, data_get($secondOccurrence, 'event_parties.0.party_ref_id'));
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'event_parties.1.party_ref_id'));
        $this->assertSame((string) $this->artist->_id, data_get($secondOccurrence, 'linked_account_profiles.0.id'));
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'linked_account_profiles.1.id'));
        $this->assertSame('17:00', data_get($secondOccurrence, 'programming_items.0.time'));
        $this->assertSame('Show com a banda', data_get($secondOccurrence, 'programming_items.0.title'));
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'programming_items.0.linked_account_profiles.0.id'));
        $this->assertSame((string) $this->venue->_id, data_get($secondOccurrence, 'programming_items.0.place_ref.id'));
        $this->assertSame((string) $this->venue->_id, data_get($secondOccurrence, 'programming_items.0.location_profile.id'));

        $updated = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'Renamed without occurrence payload',
        ]);
        $updated->assertStatus(200);
        $updated->assertJsonPath('data.occurrences.1.has_location_override', false);
        $updated->assertJsonPath('data.occurrences.1.own_linked_account_profiles.0.id', (string) $this->band->_id);
        $updated->assertJsonPath('data.occurrences.1.programming_count', 1);

        $freshSecondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();
        $this->assertSame((string) $this->band->_id, data_get($freshSecondOccurrence, 'own_event_parties.0.party_ref_id'));
        $this->assertNull(data_get($freshSecondOccurrence, 'location_override'));
        $this->assertSame('17:00', data_get($freshSecondOccurrence, 'programming_items.0.time'));
    }

    public function test_account_event_create_rejects_foreign_programming_location_profile(): void
    {
        $foreignVenue = $this->createAccountProfile('venue', 'Foreign Programming Venue');
        $this->assertNotSame((string) $this->account->_id, (string) $foreignVenue->account_id);

        $occurrences = $this->makeOccurrences(1);
        $occurrences[0]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Foreign host attempt',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $foreignVenue->_id,
            ],
        ]];

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'occurrences.0.programming_items.0.place_ref.id',
        ]);
    }

    public function test_account_event_update_rejects_foreign_main_and_programming_location_profiles(): void
    {
        $foreignMainVenue = $this->createAccountProfile('venue', 'Foreign Main Venue');
        $foreignProgrammingVenue = $this->createAccountProfile('venue', 'Foreign Program Venue');
        $this->assertNotSame((string) $this->account->_id, (string) $foreignMainVenue->account_id);
        $this->assertNotSame((string) $this->account->_id, (string) $foreignProgrammingVenue->account_id);

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $created->assertStatus(201);
        $eventId = (string) $created->json('data.event_id');

        $mainLocationResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'location' => ['mode' => 'physical'],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $foreignMainVenue->_id,
            ],
        ]);

        $mainLocationResponse->assertStatus(422);
        $mainLocationResponse->assertJsonValidationErrors(['place_ref.id']);

        $occurrences = $this->makeOccurrences(1);
        $occurrences[0]['programming_items'] = [[
            'time' => '18:00',
            'title' => 'Foreign program host attempt',
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $foreignProgrammingVenue->_id,
            ],
        ]];

        $programmingResponse = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'occurrences' => $occurrences,
        ]);

        $programmingResponse->assertStatus(422);
        $programmingResponse->assertJsonValidationErrors([
            'occurrences.0.programming_items.0.place_ref.id',
        ]);
    }

    public function test_event_create_rejects_occurrence_location_override(): void
    {
        $occurrences = $this->makeOccurrences(2);
        $occurrences[1]['location'] = [
            'mode' => 'online',
            'online' => [
                'url' => 'https://example.org/live',
            ],
        ];

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences.1.location']);
    }

    public function test_public_event_detail_selects_occurrence_and_returns_all_dates_with_selected_highlight(): void
    {
        $occurrences = $this->makeOccurrences(2);
        $occurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
        ]];
        $occurrences[1]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $secondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $response = $this->getJson("{$this->base_api_tenant}events/{$eventId}?occurrence={$secondOccurrence->_id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.occurrence_id', (string) $secondOccurrence->_id);
        $response->assertJsonPath('data.occurrences.0.is_selected', false);
        $response->assertJsonPath('data.occurrences.1.is_selected', true);
        $response->assertJsonPath('data.location.mode', 'physical');
        $response->assertJsonPath('data.place_ref.id', (string) $this->venue->_id);
        $response->assertJsonPath('data.latitude', -20);
        $response->assertJsonPath('data.longitude', -40);
        $response->assertJsonPath('data.linked_account_profiles.0.id', (string) $this->artist->_id);
        $response->assertJsonPath('data.linked_account_profiles.1.id', (string) $this->band->_id);
        $response->assertJsonPath('data.programming_items.0.title', 'Show com a banda');
        $response->assertJsonPath('data.programming_items.0.linked_account_profiles.0.id', (string) $this->band->_id);
        $response->assertJsonPath('data.programming_items.0.place_ref.id', (string) $this->venue->_id);
        $response->assertJsonPath('data.programming_items.0.location_profile.id', (string) $this->venue->_id);

        $staleResponse = $this->getJson("{$this->base_api_tenant}events/{$eventId}?occurrence=000000000000000000000000");
        $staleResponse->assertStatus(200);
        $staleResponse->assertJsonPath('data.occurrences.0.is_selected', true);
        $staleResponse->assertJsonPath('data.occurrences.1.is_selected', false);
    }

    public function test_public_event_detail_occurrence_slug_alias_selects_occurrence(): void
    {
        $occurrences = $this->makeOccurrences(2);
        $occurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
        ]];
        $occurrences[1]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $eventSlug = (string) $created->json('data.slug');
        $secondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $aliasResponse = $this->getJson("{$this->base_api_tenant}events/{$secondOccurrence->occurrence_slug}");

        $aliasResponse->assertStatus(200);
        $aliasResponse->assertJsonPath('data.event_id', $eventId);
        $aliasResponse->assertJsonPath('data.slug', $eventSlug);
        $aliasResponse->assertJsonPath('data.occurrence_id', (string) $secondOccurrence->_id);
        $aliasResponse->assertJsonPath('data.occurrences.0.is_selected', false);
        $aliasResponse->assertJsonPath('data.occurrences.1.is_selected', true);
        $aliasResponse->assertJsonPath('data.programming_items.0.title', 'Show com a banda');
        $aliasResponse->assertJsonPath('data.programming_items.0.location_profile.id', (string) $this->venue->_id);

        $staleQueryResponse = $this->getJson("{$this->base_api_tenant}events/{$secondOccurrence->occurrence_slug}?occurrence=000000000000000000000000");
        $staleQueryResponse->assertStatus(200);
        $staleQueryResponse->assertJsonPath('data.event_id', $eventId);
        $staleQueryResponse->assertJsonPath('data.occurrences.0.is_selected', true);
        $staleQueryResponse->assertJsonPath('data.occurrences.1.is_selected', false);
    }

    public function test_public_event_detail_related_profiles_aggregate_event_occurrences_and_programming_profiles(): void
    {
        $guestArtist = $this->createAccountProfile('artist', 'Convidado Programacao');
        $guestArtist->slug = 'convidado-programacao-'.Str::lower(Str::random(6));
        $guestArtist->save();

        $occurrences = $this->makeOccurrences(2);
        $occurrences[0]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
        ]];
        $occurrences[0]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show principal',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];
        $occurrences[1]['programming_items'] = [[
            'time' => '19:00',
            'title' => 'Show convidado',
            'account_profile_ids' => [(string) $guestArtist->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Detail profile aggregation',
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $selectedOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $response = $this->getJson("{$this->base_api_tenant}events/{$eventId}?occurrence={$selectedOccurrence->_id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.occurrence_id', (string) $selectedOccurrence->_id);
        $this->assertSame(
            [
                (string) $this->artist->_id,
                (string) $this->band->_id,
                (string) $guestArtist->_id,
            ],
            collect($response->json('data.linked_account_profiles') ?? [])
                ->pluck('id')
                ->map(static fn ($id) => (string) $id)
                ->values()
                ->all()
        );
        $response->assertJsonMissingPath('data.programming_items.1');
        $response->assertJsonPath('data.programming_items.0.linked_account_profiles.0.id', (string) $this->band->_id);
    }

    public function test_public_event_detail_repeated_gets_do_not_degrade_programming_or_occurrence_profiles(): void
    {
        $firstOccurrences = $this->makeOccurrences(2);
        $firstOccurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
        ]];
        $firstOccurrences[1]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $secondOccurrences = $this->makeOccurrences(2);
        $secondOccurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $this->artist->_id,
        ]];
        $secondOccurrences[1]['programming_items'] = [[
            'time' => '19:00',
            'title' => 'Pocket show',
            'account_profile_ids' => [(string) $this->artist->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $firstCreated = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'GET Stability A',
            'occurrences' => $firstOccurrences,
        ]));
        $firstCreated->assertStatus(201);

        $secondCreated = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'GET Stability B',
            'occurrences' => $secondOccurrences,
        ]));
        $secondCreated->assertStatus(201);

        $firstEventId = (string) $firstCreated->json('data.event_id');
        $secondEventId = (string) $secondCreated->json('data.event_id');

        $firstSelectedOccurrence = EventOccurrence::query()
            ->where('event_id', $firstEventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();
        $secondSelectedOccurrence = EventOccurrence::query()
            ->where('event_id', $secondEventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $firstRead = $this->getJson("{$this->base_api_tenant}events/{$firstEventId}?occurrence={$firstSelectedOccurrence->_id}");
        $firstRead->assertStatus(200);
        $firstRead->assertJsonPath('data.occurrence_id', (string) $firstSelectedOccurrence->_id);
        $firstRead->assertJsonPath('data.linked_account_profiles.1.id', (string) $this->band->_id);
        $firstRead->assertJsonPath('data.programming_items.0.title', 'Show com a banda');
        $firstRead->assertJsonPath('data.programming_items.0.linked_account_profiles.0.id', (string) $this->band->_id);

        $secondRead = $this->getJson("{$this->base_api_tenant}events/{$secondEventId}?occurrence={$secondSelectedOccurrence->_id}");
        $secondRead->assertStatus(200);
        $secondRead->assertJsonPath('data.occurrence_id', (string) $secondSelectedOccurrence->_id);
        $secondRead->assertJsonPath('data.programming_items.0.title', 'Pocket show');
        $secondRead->assertJsonPath('data.programming_items.0.linked_account_profiles.0.id', (string) $this->artist->_id);
        $this->assertTrue(
            collect($secondRead->json('data.linked_account_profiles') ?? [])
                ->contains(fn (array $profile): bool => ($profile['id'] ?? null) === (string) $this->artist->_id)
        );

        $firstReadAgain = $this->getJson("{$this->base_api_tenant}events/{$firstEventId}?occurrence={$firstSelectedOccurrence->_id}");
        $firstReadAgain->assertStatus(200);
        $firstReadAgain->assertJsonPath('data.occurrence_id', (string) $firstSelectedOccurrence->_id);
        $firstReadAgain->assertJsonPath('data.linked_account_profiles.1.id', (string) $this->band->_id);
        $firstReadAgain->assertJsonPath('data.programming_items.0.title', 'Show com a banda');
        $firstReadAgain->assertJsonPath('data.programming_items.0.linked_account_profiles.0.id', (string) $this->band->_id);

        $freshFirstSelectedOccurrence = $firstSelectedOccurrence->fresh();
        $freshSecondSelectedOccurrence = $secondSelectedOccurrence->fresh();

        $this->assertSame('17:00', data_get($freshFirstSelectedOccurrence, 'programming_items.0.time'));
        $this->assertSame('Show com a banda', data_get($freshFirstSelectedOccurrence, 'programming_items.0.title'));
        $this->assertSame((string) $this->band->_id, data_get($freshFirstSelectedOccurrence, 'programming_items.0.linked_account_profiles.0.id'));
        $this->assertSame((string) $this->band->_id, data_get($freshFirstSelectedOccurrence, 'own_linked_account_profiles.0.id'));

        $this->assertSame('19:00', data_get($freshSecondSelectedOccurrence, 'programming_items.0.time'));
        $this->assertSame('Pocket show', data_get($freshSecondSelectedOccurrence, 'programming_items.0.title'));
        $this->assertSame((string) $this->artist->_id, data_get($freshSecondSelectedOccurrence, 'programming_items.0.linked_account_profiles.0.id'));
        $this->assertSame((string) $this->artist->_id, data_get($freshSecondSelectedOccurrence, 'own_linked_account_profiles.0.id'));
    }

    public function test_agenda_occurrence_cards_only_include_event_profiles_and_their_own_occurrence_profiles(): void
    {
        $guestArtist = $this->createAccountProfile('artist', 'DJ Visitante');
        $guestArtist->slug = 'dj-visitante-'.Str::lower(Str::random(6));
        $guestArtist->save();

        $occurrences = $this->makeOccurrences(2);
        $occurrences[0]['event_parties'] = [[
            'party_ref_id' => (string) $this->band->_id,
        ]];
        $occurrences[0]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com a banda',
            'account_profile_ids' => [(string) $this->band->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];
        $occurrences[1]['event_parties'] = [[
            'party_ref_id' => (string) $guestArtist->_id,
        ]];
        $occurrences[1]['programming_items'] = [[
            'time' => '19:00',
            'title' => 'Show convidado',
            'account_profile_ids' => [(string) $guestArtist->_id],
            'place_ref' => [
                'type' => 'account_profile',
                'id' => (string) $this->venue->_id,
            ],
        ]];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Agenda occurrence scoped profiles',
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $event = Event::query()->findOrFail($eventId);
        $this->assertSame(
            [(string) $this->artist->_id],
            collect($event->event_parties ?? [])->pluck('party_ref_id')->map(static fn ($id) => (string) $id)->values()->all()
        );

        $firstOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();
        $secondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $agenda = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=20");
        $agenda->assertStatus(200);

        $itemsByOccurrenceId = collect($agenda->json('items', []))
            ->keyBy(static fn (array $item): string => (string) ($item['occurrence_id'] ?? ''));

        $firstItem = $itemsByOccurrenceId->get((string) $firstOccurrence->_id);
        $secondItem = $itemsByOccurrenceId->get((string) $secondOccurrence->_id);

        $this->assertNotNull($firstItem);
        $this->assertNotNull($secondItem);

        $this->assertSame(
            [(string) $this->artist->_id, (string) $this->band->_id],
            collect($firstItem['linked_account_profiles'] ?? [])
                ->pluck('id')
                ->map(static fn ($id) => (string) $id)
                ->values()
                ->all()
        );
        $this->assertSame(
            [(string) $this->artist->_id, (string) $guestArtist->_id],
            collect($secondItem['linked_account_profiles'] ?? [])
                ->pluck('id')
                ->map(static fn ($id) => (string) $id)
                ->values()
                ->all()
        );
        $this->assertFalse(
            collect($firstItem['linked_account_profiles'] ?? [])
                ->pluck('id')
                ->contains((string) $guestArtist->_id)
        );
        $this->assertFalse(
            collect($secondItem['linked_account_profiles'] ?? [])
                ->pluck('id')
                ->contains((string) $this->band->_id)
        );
    }

    public function test_event_create_requires_programming_title_when_more_than_one_profile_is_linked(): void
    {
        $occurrences = $this->makeOccurrences(2);
        $occurrences[0]['programming_items'] = [[
            'time' => '17:00',
            'account_profile_ids' => [
                (string) $this->artist->_id,
                (string) $this->band->_id,
            ],
        ]];

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'occurrences.0.programming_items.0.title',
        ]);
    }

    public function test_event_create_allows_three_occurrences_without_tenant_max_guard(): void
    {
        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(3),
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('data.occurrences'));
    }

    public function test_event_create_rejects_unbounded_occurrence_fanout(): void
    {
        $payload = $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(InputConstraints::EVENT_OCCURRENCES_MAX + 1),
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences']);
    }

    public function test_event_create_rejects_unbounded_tags_categories_and_taxonomy_terms(): void
    {
        $oversizedTags = array_map(
            static fn (int $index): string => "tag-{$index}",
            range(1, InputConstraints::EVENT_TAGS_MAX + 1)
        );
        $oversizedCategories = array_map(
            static fn (int $index): string => "category-{$index}",
            range(1, InputConstraints::EVENT_CATEGORIES_MAX + 1)
        );
        $oversizedTaxonomyTerms = array_map(
            static fn (int $index): array => [
                'type' => 'event_style',
                'value' => "term-{$index}",
            ],
            range(1, InputConstraints::EVENT_TAXONOMY_TERMS_MAX + 1)
        );

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'tags' => $oversizedTags,
            'categories' => $oversizedCategories,
            'taxonomy_terms' => $oversizedTaxonomyTerms,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'tags',
            'categories',
            'taxonomy_terms',
        ]);
    }

    public function test_event_create_rejects_unbounded_unique_taxonomy_terms_before_resolver_work(): void
    {
        $taxonomyTerms = array_map(
            static fn (int $index): array => [
                'type' => 'event_style',
                'value' => "unique-term-{$index}",
            ],
            range(1, InputConstraints::EVENT_TAXONOMY_UNIQUE_TERMS_MAX + 1)
        );

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'taxonomy_terms' => $taxonomyTerms,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['taxonomy_terms']);
    }

    public function test_event_create_rejects_unbounded_programming_items_per_occurrence(): void
    {
        $occurrences = $this->makeOccurrences(1);
        $occurrences[0]['programming_items'] = [];
        for ($index = 0; $index <= InputConstraints::EVENT_PROGRAMMING_ITEMS_MAX; $index++) {
            $occurrences[0]['programming_items'][] = [
                'time' => sprintf('%02d:%02d', intdiv($index, 60), $index % 60),
                'title' => "Programação {$index}",
            ];
        }

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences.0.programming_items']);
    }

    public function test_event_create_rejects_unbounded_programming_profile_links(): void
    {
        $occurrences = $this->makeOccurrences(1);
        $occurrences[0]['programming_items'] = [[
            'time' => '17:00',
            'title' => 'Show com muitos perfis',
            'account_profile_ids' => array_map(
                static fn (int $index): string => (string) new ObjectId,
                range(0, InputConstraints::EVENT_PROGRAMMING_PROFILE_IDS_MAX)
            ),
        ]];

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'occurrences.0.programming_items.0.account_profile_ids',
        ]);
    }

    public function test_event_create_rejects_unbounded_total_programming_items(): void
    {
        $occurrences = $this->makeOccurrences(3);
        foreach ($occurrences as $occurrenceIndex => $_) {
            $occurrences[$occurrenceIndex]['programming_items'] = [];
            for ($index = 0; $index < InputConstraints::EVENT_PROGRAMMING_ITEMS_MAX; $index++) {
                $occurrences[$occurrenceIndex]['programming_items'][] = [
                    'time' => sprintf('%02d:%02d', intdiv($index, 60), $index % 60),
                    'title' => "Programação {$occurrenceIndex}-{$index}",
                ];
            }
        }

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrences']);
    }

    public function test_event_create_rejects_unbounded_total_programming_references(): void
    {
        $occurrences = $this->makeOccurrences(21);
        foreach ($occurrences as $occurrenceIndex => $_) {
            $occurrences[$occurrenceIndex]['programming_items'] = [[
                'time' => '17:00',
                'title' => "Programação {$occurrenceIndex}",
                'account_profile_ids' => array_map(
                    static fn (int $index): string => (string) new ObjectId,
                    range(1, InputConstraints::EVENT_PROGRAMMING_PROFILE_IDS_MAX)
                ),
            ]];
        }

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'occurrences.*.programming_items',
        ]);
    }

    public function test_event_create_rejects_unbounded_total_occurrence_parties(): void
    {
        $occurrences = $this->makeOccurrences(4);
        foreach ($occurrences as $occurrenceIndex => $_) {
            $occurrences[$occurrenceIndex]['event_parties'] = array_map(
                static fn (int $index): array => [
                    'party_ref_id' => (string) new ObjectId,
                    'permissions' => ['can_edit' => false],
                ],
                range(1, InputConstraints::EVENT_OCCURRENCE_PARTIES_MAX)
            );
        }

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $occurrences,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_parties']);
    }

    public function test_event_update_without_schedule_mutation_keeps_stored_occurrences(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');

        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'Renamed without touching schedule',
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.occurrences'));
    }

    public function test_event_update_with_schedule_mutation_allows_multiple_occurrences_without_capability(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'occurrences' => $this->makeOccurrences(2),
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');

        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'occurrences' => $this->makeOccurrences(2),
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.occurrences'));
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

    public function test_event_delete_moves_event_from_active_to_archived_admin_lists(): void
    {
        $event = $this->createEvent([
            'title' => 'Delete Me Into Archived',
        ]);

        $response = $this->deleteJson("{$this->accountEventsBase}/{$event->_id}");
        $response->assertStatus(200);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $activeList = $this->getJson($this->tenantAdminEventsBase);
        $activeList->assertStatus(200);
        $activeTitles = collect($activeList->json('data'))->pluck('title')->all();
        $this->assertNotContains('Delete Me Into Archived', $activeTitles);

        $archivedList = $this->getJson("{$this->tenantAdminEventsBase}?archived=1");
        $archivedList->assertStatus(200);
        $archivedTitles = collect($archivedList->json('data'))->pluck('title')->all();
        $this->assertContains('Delete Me Into Archived', $archivedTitles);
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

    public function test_event_create_rolls_back_when_occurrence_sync_fails_mid_flight(): void
    {
        $payload = $this->makeEventPayload([
            'title' => 'Create Rollback Guard',
        ]);

        $this->withExceptionHandling();
        $this->mock(EventOccurrenceSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncFromEvent')
                ->once()
                ->andThrow(new \RuntimeException('forced create occurrence sync failure'));
        });

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(500);
        $this->assertFalse(Event::query()->where('title', 'Create Rollback Guard')->exists());
        $this->assertSame(0, EventOccurrence::query()->count());
    }

    public function test_event_update_rolls_back_when_occurrence_sync_fails_mid_flight(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Before Update Rollback',
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $this->withExceptionHandling();
        $this->mock(EventOccurrenceSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncFromEvent')
                ->once()
                ->andThrow(new \RuntimeException('forced update occurrence sync failure'));
        });

        $response = $this->patchJson("{$this->accountEventsBase}/{$eventId}", [
            'title' => 'After Update Rollback',
        ]);

        $response->assertStatus(500);

        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $this->assertSame('Before Update Rollback', (string) $event->title);
        $this->assertSame('Before Update Rollback', (string) $occurrence->title);
    }

    public function test_event_delete_rolls_back_when_occurrence_soft_delete_fails_mid_flight(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Delete Rollback Guard',
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $this->withExceptionHandling();
        $this->mock(EventOccurrenceSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('softDeleteByEventId')
                ->once()
                ->withArgs(static fn (string $candidate): bool => $candidate !== '')
                ->andThrow(new \RuntimeException('forced delete occurrence soft-delete failure'));
        });

        $response = $this->deleteJson("{$this->accountEventsBase}/{$eventId}");

        $response->assertStatus(500);

        $event = Event::withTrashed()->findOrFail($eventId);
        $occurrence = EventOccurrence::withTrashed()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $this->assertNull($event->deleted_at);
        $this->assertNull($occurrence->deleted_at);
    }

    public function test_scheduled_publication_rolls_back_when_occurrence_mirror_fails_mid_flight(): void
    {
        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Scheduled Publication Rollback Guard',
            'publication' => [
                'status' => 'publish_scheduled',
                'publish_at' => Carbon::now()->subMinute()->toISOString(),
            ],
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $this->mock(EventOccurrenceSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('mirrorPublicationByEventId')
                ->once()
                ->withArgs(static fn (string $candidate, array $publication): bool => $candidate !== ''
                    && ($publication['status'] ?? null) === 'published')
                ->andThrow(new \RuntimeException('forced publication mirror failure'));
        });

        try {
            app()->call([new PublishScheduledEventsJob, 'handle']);
            $this->fail('Expected scheduled publication to abort when occurrence mirroring fails.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced publication mirror failure', $exception->getMessage());
        }

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 0)
            ->firstOrFail();

        $publication = is_array($event->publication) ? $event->publication : (array) $event->publication;

        $this->assertSame('publish_scheduled', $publication['status'] ?? null);
        $this->assertFalse((bool) ($occurrence->is_event_published ?? false));
    }

    public function test_manual_occurrence_repair_preserves_occurrence_owned_profiles_and_programming_items(): void
    {
        $now = Carbon::now();
        $occurrences = [
            [
                'date_time_start' => $now->copy()->addDay()->setHour(20)->setMinute(0)->setSecond(0)->toISOString(),
                'date_time_end' => $now->copy()->addDay()->setHour(22)->setMinute(0)->setSecond(0)->toISOString(),
            ],
            [
                'date_time_start' => $now->copy()->addDays(2)->setHour(17)->setMinute(0)->setSecond(0)->toISOString(),
                'date_time_end' => $now->copy()->addDays(2)->setHour(21)->setMinute(0)->setSecond(0)->toISOString(),
                'event_parties' => [
                    [
                        'party_ref_id' => (string) $this->band->_id,
                    ],
                ],
                'programming_items' => [[
                    'time' => '17:00',
                    'title' => 'Show com a banda',
                    'account_profile_ids' => [(string) $this->band->_id],
                    'place_ref' => [
                        'type' => 'account_profile',
                        'id' => (string) $this->venue->_id,
                    ],
                ]],
            ],
        ];

        $created = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Repair Guard Source',
            'occurrences' => $occurrences,
        ]));
        $created->assertStatus(201);

        $eventId = (string) $created->json('data.event_id');
        $event = Event::query()->findOrFail($eventId);
        $event->title = 'Repair Guard Canonical Title';
        $event->save();

        app(EventOccurrenceReconciliationService::class)->reconcileEvent($event->fresh());
        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

        $secondOccurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('occurrence_index', 1)
            ->firstOrFail();

        $this->assertSame('Repair Guard Canonical Title', (string) $secondOccurrence->title);
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'own_event_parties.0.party_ref_id'));
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'own_linked_account_profiles.0.id'));
        $this->assertSame('17:00', data_get($secondOccurrence, 'programming_items.0.time'));
        $this->assertSame('Show com a banda', data_get($secondOccurrence, 'programming_items.0.title'));
        $this->assertSame((string) $this->band->_id, data_get($secondOccurrence, 'programming_items.0.linked_account_profiles.0.id'));
        $this->assertSame((string) $this->venue->_id, data_get($secondOccurrence, 'programming_items.0.place_ref.id'));
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
        $this->assertSame(
            data_get($occurrence, 'event_parties.0.metadata.slug'),
            data_get($occurrence, 'linked_account_profiles.0.slug')
        );
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
        $otherArtist = $this->createAccountProfile('artist', 'Editable Artist', $otherAccount);

        $event->event_parties = [
            [
                'party_type' => 'artist',
                'party_ref_id' => (string) $otherArtist->_id,
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

        $otherAccount = Account::create([
            'name' => 'Read Only Artist Account',
            'document' => (string) Str::uuid(),
        ]);
        $otherArtist = $this->createAccountProfile('artist', 'Read Only Artist', $otherAccount);

        $event->event_parties = [
            [
                'party_type' => 'artist',
                'party_ref_id' => (string) $otherArtist->_id,
                'permissions' => ['can_edit' => false],
            ],
        ];
        $event->save();

        $role = $otherAccount->roleTemplates()->create([
            'name' => 'Read Only Artist Role',
            'permissions' => ['*'],
        ]);

        $otherUser = $this->userService->create($otherAccount, [
            'name' => 'Read Only Artist User',
            'email' => uniqid('readonly-artist', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
        Sanctum::actingAs($otherUser, ['events:read', 'events:update']);

        $otherBase = "{$this->base_api_tenant}accounts/{$otherAccount->slug}/events/{$event->_id}";
        $response = $this->patchJson($otherBase, [
            'title' => 'Should Not Update',
        ]);

        $response->assertStatus(404);
    }

    public function test_event_owner_can_manage_event_when_party_can_edit_is_false(): void
    {
        $event = $this->createEvent();

        $event->event_parties = [
            [
                'party_type' => 'artist',
                'party_ref_id' => (string) $this->artist->_id,
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
        $otherArtist = $this->createAccountProfile('artist', 'Detached Ownership Artist', $otherAccount);

        $event->event_parties = [
            [
                'party_type' => 'artist',
                'party_ref_id' => (string) $otherArtist->_id,
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

    public function test_event_creator_cannot_update_through_unrelated_account_route(): void
    {
        $event = $this->createEvent();

        $otherAccount = Account::create([
            'name' => 'Unrelated Creator Account',
            'document' => (string) Str::uuid(),
        ]);
        $role = $otherAccount->roleTemplates()->create([
            'name' => 'Unrelated Creator Events Role',
            'permissions' => ['*'],
        ]);
        $otherUser = $this->userService->create($otherAccount, [
            'name' => 'Unrelated Creator User',
            'email' => uniqid('unrelated-creator', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        $event->created_by = [
            'type' => 'account_user',
            'id' => (string) $otherUser->_id,
        ];
        $event->save();

        Sanctum::actingAs($otherUser, ['events:read', 'events:update']);

        $response = $this->patchJson(
            "{$this->base_api_tenant}accounts/{$otherAccount->slug}/events/{$event->_id}",
            ['title' => 'Should Not Cross Account Boundary']
        );

        $response->assertStatus(404);
        $this->assertSame('Stored Event', (string) $event->fresh()->title);
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
            'event_parties' => [
                [
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => true],
                ],
            ],
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

    private function htmlParagraphOfSanitizedByteLength(int $targetBytes): string
    {
        $wrapperBytes = strlen('<p></p>');
        $bodyBytes = max(0, $targetBytes - $wrapperBytes);

        return '<p>'.str_repeat('a', $bodyBytes).'</p>';
    }

    private function createEvent(array $overrides = []): Event
    {
        $now = Carbon::now();

        $payload = array_merge([
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
            'tags' => ['music'],
            'categories' => ['culture'],
            'taxonomy_terms' => [],
            'created_by' => [
                'type' => 'account_user',
                'id' => (string) $this->user->_id,
            ],
            'event_parties' => [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $this->artist->_id,
                    'permissions' => ['can_edit' => true],
                    'metadata' => [
                        'display_name' => $this->artist->display_name,
                        'slug' => $this->artist->slug ? (string) $this->artist->slug : null,
                        'profile_type' => (string) $this->artist->profile_type,
                        'avatar_url' => $this->artist->avatar_url ?? null,
                        'cover_url' => $this->artist->cover_url ?? null,
                        'taxonomy_terms' => [],
                    ],
                ],
            ],
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'is_active' => true,
        ], $overrides);
        $payload['account_context_ids'] ??= $this->accountContextIdsForEventPayload($payload);

        $event = Event::create($payload);

        $occurrences = [[
            'date_time_start' => Carbon::instance($event->date_time_start),
            'date_time_end' => $event->date_time_end ? Carbon::instance($event->date_time_end) : null,
        ]];

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, $occurrences);

        return $event->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function accountContextIdsForEventPayload(array $payload): array
    {
        $profileIds = [];

        $placeRef = $payload['place_ref'] ?? null;
        if (is_array($placeRef)) {
            $placeRefId = trim((string) ($placeRef['id'] ?? ($placeRef['_id'] ?? '')));
            if ($placeRefId !== '') {
                $profileIds[] = $placeRefId;
            }
        }

        foreach (($payload['event_parties'] ?? []) as $party) {
            if (! is_array($party)) {
                continue;
            }

            $partyRefId = trim((string) ($party['party_ref_id'] ?? ''));
            if ($partyRefId !== '') {
                $profileIds[] = $partyRefId;
            }
        }

        if ($profileIds === []) {
            return [];
        }

        return AccountProfile::query()
            ->whereIn('_id', array_values(array_unique($profileIds)))
            ->get(['account_id'])
            ->map(static fn (AccountProfile $profile): string => trim((string) ($profile->account_id ?? '')))
            ->filter(static fn (string $accountId): bool => $accountId !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEventFixtureRows(string $fileName): array
    {
        $path = base_path("tests/Fixtures/Events/{$fileName}");
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;

        return array_values(array_filter(
            is_array($rows) ? $rows : [],
            static fn (mixed $row): bool => is_array($row)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertLegacyArchivedEventFixture(array $row): void
    {
        $document = $row;
        $document['_id'] = $this->fixtureObjectId($row['_id'] ?? null);
        $document['created_at'] = $this->fixtureUtcDateTime($row['created_at'] ?? null);
        $document['updated_at'] = $this->fixtureUtcDateTime($row['updated_at'] ?? null);
        $document['deleted_at'] = $this->fixtureUtcDateTime($row['deleted_at'] ?? null);

        Event::raw(static fn ($collection) => $collection->insertOne($document));
    }

    private function fixtureObjectId(mixed $raw): ObjectId|string
    {
        $value = '';
        if (is_array($raw)) {
            $value = trim((string) ($raw['$oid'] ?? $raw['oid'] ?? ''));
        } elseif (is_string($raw)) {
            $value = trim($raw);
        }

        if ($value !== '' && preg_match('/^[a-f0-9]{24}$/i', $value) === 1) {
            return new ObjectId($value);
        }

        return $value;
    }

    private function fixtureUtcDateTime(mixed $raw): ?UTCDateTime
    {
        $value = null;
        if (is_array($raw)) {
            $value = $raw['$date'] ?? $raw['date'] ?? null;
        } else {
            $value = $raw;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return new UTCDateTime((int) Carbon::parse($value)->valueOf());
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
