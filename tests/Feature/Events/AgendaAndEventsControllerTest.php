<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;
use Tests\Helpers\TenantLabels;

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
            ],
        ]);
    }

    public function testAgendaDefaultReturnsUpcomingAndNow(): void
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

    public function testAgendaPastOnlyReturnsPastNotOngoing(): void
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

    public function testAgendaSearchMatchesArtistsAndVenue(): void
    {
        $this->createEvent([
            'title' => 'Search Event',
            'venue' => [
                'id' => 'venue-1',
                'display_name' => 'Club Aurora',
            ],
            'artists' => [
                [
                    'id' => 'artist-1',
                    'display_name' => 'DJ Solar',
                    'avatar_url' => null,
                    'highlight' => false,
                    'genres' => ['house'],
                ],
            ],
        ]);

        $response = $this->getJson("{$this->base_api_tenant}agenda?search=solar&page=1&page_size=10");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('items'));

        $response = $this->getJson("{$this->base_api_tenant}agenda?search=club&page=1&page_size=10");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('items'));
    }

    public function testAgendaGeoFiltersExcludeEventsOutsideDistance(): void
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

    public function testEventDetailResolvesSlugAndId(): void
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

    public function testEventDetailReturns404WhenMissing(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}events/missing-event");
        $response->assertStatus(404);
    }

    public function testEventStreamReturnsDeltas(): void
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

    public function testEventStreamReconnectUsesLastEventIdWithoutReplay(): void
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

    public function testEventStreamReturnsEmptyPayloadForInvalidLastEventId(): void
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

    public function testEventStreamReturnsDeletedDeltaForFutureScheduledPublication(): void
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

    public function testAgendaRequiresAuth(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $response = $this->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");
        $response->assertStatus(401);
    }

    public function testAgendaValidatesOriginPairs(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}agenda?origin_lat=10&page=1&page_size=10");
        $response->assertStatus(422);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Test Role',
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Test User',
            'email' => uniqid('event-user', true) . '@example.org',
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
