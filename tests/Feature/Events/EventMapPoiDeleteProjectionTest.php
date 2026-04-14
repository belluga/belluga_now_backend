<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\EventType;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class EventMapPoiDeleteProjectionTest extends TestCaseTenant
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

        MapPoi::query()->delete();
        Event::withTrashed()->forceDelete();
        EventOccurrence::withTrashed()->forceDelete();
        EventType::query()->delete();
        AccountProfile::query()->delete();

        [$this->account] = $this->seedAccountWithRole(['*']);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['*']);

        Sanctum::actingAs($this->user, [
            'events:read',
            'events:create',
            'events:update',
            'events:delete',
        ]);

        $this->venue = $this->createAccountProfile('venue', 'Delete Projection Venue', $this->account);
        $this->artist = $this->createAccountProfile('artist', 'Delete Projection Artist');

        $this->eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#123456',
        ]);

        $this->accountEventsBase = "{$this->base_api_tenant}accounts/{$this->account->slug}/events";
    }

    public function test_event_delete_removes_map_poi_projection(): void
    {
        $createResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload());
        $createResponse->assertStatus(201);

        $eventId = (string) $createResponse->json('data.event_id');
        $event = Event::query()->find($eventId);
        $this->assertNotNull($event);

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event);
        $controlResponse = $this->postJson($this->accountEventsBase, $this->makeEventPayload([
            'title' => 'Delete Projection Control Event',
        ]));
        $controlResponse->assertStatus(201);
        $controlEventId = (string) $controlResponse->json('data.event_id');
        $controlEvent = Event::query()->find($controlEventId);
        $this->assertNotNull($controlEvent);

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($controlEvent);

        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $eventId)
                ->exists()
        );

        $deleteResponse = $this->deleteJson("{$this->accountEventsBase}/{$eventId}");

        $deleteResponse->assertStatus(200);
        $this->assertFalse(
            MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $eventId)
                ->exists()
        );
        $this->assertTrue(
            MapPoi::query()
                ->where('ref_type', 'event')
                ->where('ref_id', $controlEventId)
                ->exists()
        );
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

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->firstOrFail();
        $role->permissions = $permissions;
        $role->save();

        return $this->userService->create($this->account, [
            'name' => 'Events User',
            'email' => uniqid('events-user-delete', true).'@example.org',
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
            'title' => 'Delete Projection Event',
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
            'publication' => [
                'status' => 'published',
            ],
        ], $overrides);
    }
}
