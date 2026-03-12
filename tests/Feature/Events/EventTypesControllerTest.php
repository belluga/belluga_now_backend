<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\EventType;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class EventTypesControllerTest extends TestCaseTenant
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

        EventType::query()->delete();
        Event::query()->delete();
        EventOccurrence::query()->delete();

        $this->seedAccountWithRole([
            'events:read',
            'events:create',
            'events:update',
            'events:delete',
        ]);
    }

    public function test_event_type_index_lists_registry(): void
    {
        EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}event_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.slug', 'show');
        $response->assertJsonPath('data.0.description', 'Tipo de evento: Show');
    }

    public function test_event_type_index_allows_create_ability_token(): void
    {
        EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
        ]);

        $user = LandlordUser::query()->firstOrFail();
        $token = $user->createToken('events-create-only', ['events:create'])->plainTextToken;

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}event_types",
            [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.slug', 'show');
    }

    public function test_event_type_create(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}event_types",
            [
                'name' => 'Workshop',
                'slug' => 'workshop',
                'description' => 'Tipo de evento: Workshop',
                'icon' => 'build',
                'color' => '#334455',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'workshop');
        $response->assertJsonPath('data.description', 'Tipo de evento: Workshop');
        $this->assertNotEmpty((string) $response->json('data.id'));
    }

    public function test_event_type_create_validates_description_minimum_length(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}event_types",
            [
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'short',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['description']);
    }

    public function test_event_type_update_propagates_snapshot_to_events_and_occurrences(): void
    {
        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#112233',
        ]);

        $event = Event::query()->create([
            'title' => 'Old Event',
            'slug' => 'old-event',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
            ],
            'content' => 'Old content',
            'location' => ['mode' => 'online'],
            'publication' => [
                'status' => 'published',
                'publish_at' => now()->toISOString(),
            ],
        ]);

        EventOccurrence::query()->create([
            'event_id' => (string) $event->_id,
            'occurrence_index' => 0,
            'occurrence_slug' => 'old-event-occ-1',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
            ],
            'starts_at' => now()->addDay(),
            'is_event_published' => true,
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [
                'name' => 'Live Show',
                'description' => 'Tipo de evento atualizado: Live Show',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.name', 'Live Show');

        $this->assertSame(
            'Live Show',
            (string) (Event::query()->findOrFail($event->_id)->type['name'] ?? '')
        );
        $this->assertSame(
            'Tipo de evento atualizado: Live Show',
            (string) (EventOccurrence::query()->where('event_id', (string) $event->_id)->firstOrFail()->type['description'] ?? '')
        );
    }

    public function test_event_type_delete_rejects_when_referenced_by_events(): void
    {
        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
        ]);

        Event::query()->create([
            'title' => 'Event',
            'slug' => 'event-delete-check',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
            ],
            'content' => 'Event content',
            'location' => ['mode' => 'online'],
            'publication' => [
                'status' => 'published',
                'publish_at' => now()->toISOString(),
            ],
        ]);

        $response = $this->deleteJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_type']);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $initializer */
        $initializer = app(SystemInitializationService::class);

        $initializer->initialize(new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: [
                'name' => 'Root User',
                'email' => 'root@example.org',
                'password' => 'Secret!234',
            ],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        ));
    }
}
