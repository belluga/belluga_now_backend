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
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}event_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.slug', 'show');
        $response->assertJsonPath('data.0.description', null);
    }

    public function test_event_type_index_allows_create_ability_token(): void
    {
        EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
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
                'icon' => 'build',
                'color' => '#334455',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'workshop');
        $response->assertJsonPath('data.description', null);
        $this->assertNotEmpty((string) $response->json('data.id'));
    }

    public function test_event_type_create_accepts_missing_description(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}event_types",
            [
                'name' => 'Show',
                'slug' => 'show',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.description', null);
    }

    public function test_event_type_create_persists_allowed_taxonomies(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}event_types",
            [
                'name' => 'Festival',
                'slug' => 'festival',
                'allowed_taxonomies' => ['music_genre', 'audience'],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.allowed_taxonomies.0', 'music_genre');
        $response->assertJsonPath('data.allowed_taxonomies.1', 'audience');

        $stored = EventType::query()->where('slug', 'festival')->firstOrFail();
        $this->assertSame(['music_genre', 'audience'], $stored->allowed_taxonomies);
    }

    public function test_event_type_create_accepts_canonical_visual_type_asset_upload(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}event_types",
            [
                'name' => 'Festival',
                'slug' => 'festival',
                'description' => 'Tipo com imagem canônica',
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                ],
                'type_asset' => UploadedFile::fake()->image('festival.png', 320, 320),
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.visual.mode', 'image');
        $response->assertJsonPath('data.visual.image_source', 'type_asset');
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'type_asset');
        $typeAssetUrl = $response->json('data.visual.image_url');
        $this->assertIsString($typeAssetUrl);
        $this->assertStringContainsString('/api/v1/media/event-types/', $typeAssetUrl);
        $this->assertSame($typeAssetUrl, $response->json('data.poi_visual.image_url'));

        $model = EventType::query()->where('slug', 'festival')->firstOrFail();
        $this->assertTypeAssetStored((string) $model->getKey(), 'event_types');
    }

    public function test_event_type_update_accepts_null_description(): void
    {
        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [
                'description' => null,
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.description', null);

        $stored = EventType::query()->findOrFail($eventType->_id);
        $this->assertNull($stored->description);
    }

    public function test_event_type_update_replaces_allowed_taxonomies(): void
    {
        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'allowed_taxonomies' => ['music_genre'],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [
                'allowed_taxonomies' => ['audience'],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.allowed_taxonomies.0', 'audience');
        $response->assertJsonMissingPath('data.allowed_taxonomies.1');

        $stored = EventType::query()->findOrFail($eventType->_id);
        $this->assertSame(['audience'], $stored->allowed_taxonomies);
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

    public function test_event_type_update_rematerializes_visual_for_related_event_map_pois(): void
    {
        MapPoi::query()->delete();

        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#112233',
            'icon_color' => '#FFFFFF',
        ]);

        $event = Event::query()->create([
            'title' => 'Visual Event',
            'slug' => 'visual-event',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
            ],
            'content' => 'Visual content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'publication' => [
                'status' => 'published',
                'publish_at' => now()->subMinute()->toISOString(),
            ],
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                ],
            ],
        ]);

        EventOccurrence::query()->create([
            'event_id' => (string) $event->_id,
            'occurrence_index' => 0,
            'occurrence_slug' => 'visual-event-occ-1',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
            ],
            'starts_at' => now()->addDay(),
            'is_event_published' => true,
        ]);

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event->fresh());
        $before = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', (string) $event->_id)
            ->firstOrFail();
        $this->assertSame('#112233', data_get($before->visual, 'color'));
        $this->assertSame('#FFFFFF', data_get($before->visual, 'icon_color'));

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [
                'icon' => 'restaurant',
                'color' => '#334455',
                'icon_color' => '#101010',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.icon', 'restaurant');
        $response->assertJsonPath('data.color', '#334455');
        $response->assertJsonPath('data.icon_color', '#101010');

        $updatedEvent = Event::query()->findOrFail($event->_id);
        $this->assertSame('restaurant', data_get($updatedEvent->type, 'icon'));
        $this->assertSame('#334455', data_get($updatedEvent->type, 'color'));
        $this->assertSame('#101010', data_get($updatedEvent->type, 'icon_color'));

        $after = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', (string) $event->_id)
            ->firstOrFail();
        $this->assertSame('restaurant', data_get($after->visual, 'icon'));
        $this->assertSame('#334455', data_get($after->visual, 'color'));
        $this->assertSame('#101010', data_get($after->visual, 'icon_color'));
    }

    public function test_event_type_partial_legacy_patch_preserves_missing_icon_fields(): void
    {
        MapPoi::query()->delete();

        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#112233',
            'icon_color' => '#FFFFFF',
            'visual' => [
                'mode' => 'icon',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
            ],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
            ],
        ]);

        $event = Event::query()->create([
            'title' => 'Partial Visual Event',
            'slug' => 'partial-visual-event',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
                'visual' => [
                    'mode' => 'icon',
                    'icon' => 'music_note',
                    'color' => '#112233',
                    'icon_color' => '#FFFFFF',
                ],
            ],
            'content' => 'Visual content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'publication' => [
                'status' => 'published',
                'publish_at' => now()->subMinute()->toISOString(),
            ],
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                ],
            ],
        ]);

        EventOccurrence::query()->create([
            'event_id' => (string) $event->_id,
            'occurrence_index' => 0,
            'occurrence_slug' => 'partial-visual-event-occ-1',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
                'visual' => [
                    'mode' => 'icon',
                    'icon' => 'music_note',
                    'color' => '#112233',
                    'icon_color' => '#FFFFFF',
                ],
            ],
            'starts_at' => now()->addDay(),
            'is_event_published' => true,
        ]);

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event->fresh());

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [
                'color' => '#334455',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.icon', 'music_note');
        $response->assertJsonPath('data.color', '#334455');
        $response->assertJsonPath('data.icon_color', '#FFFFFF');
        $response->assertJsonPath('data.visual.icon', 'music_note');
        $response->assertJsonPath('data.visual.color', '#334455');
        $response->assertJsonPath('data.visual.icon_color', '#FFFFFF');

        $updatedType = EventType::query()->findOrFail($eventType->_id);
        $this->assertSame('music_note', $updatedType->icon);
        $this->assertSame('#334455', $updatedType->color);
        $this->assertSame('#FFFFFF', $updatedType->icon_color);
        $this->assertSame('music_note', data_get($updatedType->visual, 'icon'));
        $this->assertSame('#334455', data_get($updatedType->visual, 'color'));
        $this->assertSame('#FFFFFF', data_get($updatedType->visual, 'icon_color'));

        $updatedEvent = Event::query()->findOrFail($event->_id);
        $this->assertSame('music_note', data_get($updatedEvent->type, 'icon'));
        $this->assertSame('#334455', data_get($updatedEvent->type, 'color'));
        $this->assertSame('#FFFFFF', data_get($updatedEvent->type, 'icon_color'));

        $updatedOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->firstOrFail();
        $this->assertSame('music_note', data_get($updatedOccurrence->type, 'icon'));
        $this->assertSame('#334455', data_get($updatedOccurrence->type, 'color'));
        $this->assertSame('#FFFFFF', data_get($updatedOccurrence->type, 'icon_color'));

        $projection = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', (string) $event->_id)
            ->firstOrFail();
        $this->assertSame('music_note', data_get($projection->visual, 'icon'));
        $this->assertSame('#334455', data_get($projection->visual, 'color'));
        $this->assertSame('#FFFFFF', data_get($projection->visual, 'icon_color'));
    }

    public function test_event_type_update_cover_visual_rematerializes_related_event_map_pois(): void
    {
        MapPoi::query()->delete();

        $eventType = EventType::query()->create([
            'name' => 'Show',
            'slug' => 'show',
            'description' => 'Tipo de evento: Show',
            'icon' => 'music_note',
            'color' => '#112233',
            'icon_color' => '#FFFFFF',
            'visual' => [
                'mode' => 'icon',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
            ],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
            ],
        ]);

        $event = Event::query()->create([
            'title' => 'Visual Event',
            'slug' => 'visual-event-cover',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
                'visual' => [
                    'mode' => 'icon',
                    'icon' => 'music_note',
                    'color' => '#112233',
                    'icon_color' => '#FFFFFF',
                ],
            ],
            'content' => 'Visual content',
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => 'https://tenant-zeta.test/api/v1/media/events/event-cover/cover?v=1',
                ],
            ],
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'publication' => [
                'status' => 'published',
                'publish_at' => now()->subMinute()->toISOString(),
            ],
            'capabilities' => [
                'map_poi' => [
                    'enabled' => true,
                ],
            ],
        ]);

        EventOccurrence::query()->create([
            'event_id' => (string) $event->_id,
            'occurrence_index' => 0,
            'occurrence_slug' => 'visual-event-cover-occ-1',
            'type' => [
                'id' => (string) $eventType->_id,
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Tipo de evento: Show',
                'icon' => 'music_note',
                'color' => '#112233',
                'icon_color' => '#FFFFFF',
                'visual' => [
                    'mode' => 'icon',
                    'icon' => 'music_note',
                    'color' => '#112233',
                    'icon_color' => '#FFFFFF',
                ],
            ],
            'starts_at' => now()->addDay(),
            'is_event_published' => true,
        ]);

        $this->app->make(MapPoiProjectionService::class)->upsertFromEvent($event->fresh());
        $before = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', (string) $event->_id)
            ->firstOrFail();
        $this->assertSame('icon', data_get($before->visual, 'mode'));

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}event_types/{$eventType->_id}",
            [
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'cover',
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.visual.mode', 'image');
        $response->assertJsonPath('data.visual.image_source', 'cover');
        $response->assertJsonPath('data.poi_visual.mode', 'image');
        $response->assertJsonPath('data.poi_visual.image_source', 'cover');

        $updatedEvent = Event::query()->findOrFail($event->_id);
        $this->assertSame('image', data_get($updatedEvent->type, 'visual.mode'));
        $this->assertSame('cover', data_get($updatedEvent->type, 'visual.image_source'));

        $after = MapPoi::query()
            ->where('ref_type', 'event')
            ->where('ref_id', (string) $event->_id)
            ->firstOrFail();
        $this->assertSame('image', data_get($after->visual, 'mode'));
        $this->assertSame(
            'https://tenant-zeta.test/api/v1/media/events/event-cover/cover?v=1',
            data_get($after->visual, 'image_uri')
        );
        $this->assertSame('item_media', data_get($after->visual, 'source'));
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

    private function getMultipartHeaders(): array
    {
        return [
            ...$this->getHeaders(),
            'Content-Type' => 'multipart/form-data',
        ];
    }

    private function assertTypeAssetStored(string $typeId, string $directory): string
    {
        $needle = "/{$directory}/{$typeId}/type_asset.";

        foreach (Storage::disk('public')->allFiles() as $path) {
            if (str_contains($path, $needle)) {
                Storage::disk('public')->assertExists($path);

                return $path;
            }
        }

        $this->fail("Failed asserting that type asset exists for [{$typeId}] in [{$directory}].");
    }
}
