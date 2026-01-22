<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Jobs\PublishScheduledEventsJob;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\Event;
use Illuminate\Support\Carbon;
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

        $this->accountEventsBase = "{$this->base_api_tenant}accounts/{$this->account->slug}/events";
        $this->tenantAdminEventsBase = "{$this->base_tenant_api_admin}events";
    }

    public function testEventCreateStoresEvent(): void
    {
        $payload = $this->makeEventPayload();

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', $payload['title']);
        $response->assertJsonPath('data.venue_id', (string) $this->venue->_id);
        $response->assertJsonPath('data.publication.status', 'published');
        $this->assertSame($payload['type']['slug'], $response->json('data.type.slug'));
    }

    public function testEventCreateRejectsScheduledWithoutPublishAt(): void
    {
        $payload = $this->makeEventPayload([
            'publication' => [
                'status' => 'publish_scheduled',
            ],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function testEventCreateRejectsNonArtistIds(): void
    {
        $payload = $this->makeEventPayload([
            'artist_ids' => [(string) $this->venue->_id],
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function testEventCreateRejectsVenueWithoutLocation(): void
    {
        $venue = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'No Location Venue',
            'taxonomy_terms' => [],
            'is_active' => true,
            'is_verified' => false,
        ]);

        $payload = $this->makeEventPayload([
            'venue_id' => (string) $venue->_id,
        ]);

        $response = $this->postJson($this->accountEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function testEventCreateForbiddenWithoutAbility(): void
    {
        $limited = $this->createAccountUser(['*']);
        Sanctum::actingAs($limited, ['events:read']);

        $response = $this->postJson($this->accountEventsBase, $this->makeEventPayload());

        $response->assertStatus(403);
    }

    public function testEventIndexFiltersByStatus(): void
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

    public function testEventUpdateChangesFields(): void
    {
        $event = $this->createEvent();

        $response = $this->patchJson("{$this->accountEventsBase}/{$event->_id}", [
            'title' => 'Updated Title',
            'publication' => ['status' => 'ended'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'Updated Title');
        $response->assertJsonPath('data.publication.status', 'ended');
    }

    public function testEventUpdateReturns404WhenMissing(): void
    {
        $response = $this->patchJson("{$this->accountEventsBase}/missing-event", [
            'title' => 'Missing',
        ]);

        $response->assertStatus(404);
    }

    public function testEventDeleteSoftDeletes(): void
    {
        $event = $this->createEvent();

        $response = $this->deleteJson("{$this->accountEventsBase}/{$event->_id}");

        $response->assertStatus(200);

        $deleted = Event::withTrashed()->find($event->_id);
        $this->assertNotNull($deleted?->deleted_at);
    }

    public function testPublishScheduledEventsJobPromotesReadyEvents(): void
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

        (new PublishScheduledEventsJob())->handle();

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();

        $ready->refresh();
        $future->refresh();

        $readyPublication = is_array($ready->publication) ? $ready->publication : (array) $ready->publication;
        $futurePublication = is_array($future->publication) ? $future->publication : (array) $future->publication;

        $this->assertSame('published', $readyPublication['status'] ?? null);
        $this->assertSame('publish_scheduled', $futurePublication['status'] ?? null);
    }

    public function testTenantAdminCreateRequiresOnBehalfAccount(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:create']);

        $payload = $this->makeEventPayload();

        $response = $this->postJson($this->tenantAdminEventsBase, $payload);

        $response->assertStatus(422);
    }

    public function testTenantAdminCreateOnBehalfIsScopedToTargetAccount(): void
    {
        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:create', 'events:read']);

        $payload = $this->makeEventPayload([
            'account_id' => (string) $this->account->_id,
        ]);

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

    public function testAccountUserCannotManageAnotherAccountEvents(): void
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
            'email' => uniqid('other-user', true) . '@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        Sanctum::actingAs($otherUser, ['events:read', 'events:update']);

        $otherBase = "{$this->base_api_tenant}accounts/{$otherAccount->slug}/events/{$event->_id}";
        $response = $this->patchJson($otherBase, [
            'title' => 'Should Not Update',
        ]);

        $response->assertStatus(404);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Events Role ' . uniqid('role-', true),
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Events User',
            'email' => uniqid('events-user', true) . '@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createAccountProfile(string $profileType, string $displayName, ?Account $account = null): AccountProfile
    {
        $account = $account ?? Account::create([
            'name' => $displayName . ' Account',
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
            'venue_id' => (string) $this->venue->_id,
            'artist_ids' => [(string) $this->artist->_id],
            'type' => [
                'id' => 'type-1',
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Show desc',
            ],
            'date_time_start' => $now->copy()->addDay()->toISOString(),
            'date_time_end' => $now->copy()->addDay()->addHours(2)->toISOString(),
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

        return Event::create(array_merge([
            'account_id' => (string) $this->venue->account_id,
            'account_profile_id' => (string) $this->venue->_id,
            'title' => 'Stored Event',
            'content' => 'Event content',
            'type' => [
                'id' => 'type-1',
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Show desc',
                'icon' => null,
                'color' => null,
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
            'confirmed_user_ids' => [],
            'received_invites' => [],
            'sent_invites' => [],
            'friends_going' => [],
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'is_active' => true,
        ], $overrides));
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
