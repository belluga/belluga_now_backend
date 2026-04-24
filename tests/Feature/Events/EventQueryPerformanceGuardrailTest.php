<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\EventType;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventBus;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class EventQueryPerformanceGuardrailTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private EventType $eventType;

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

        $this->eventType = EventType::query()->create([
            'name' => 'Performance Guard',
            'slug' => 'performance-guard',
            'description' => 'Performance guard event type',
            'icon' => 'event',
            'color' => '#123456',
            'allowed_taxonomies' => [],
        ]);
        $this->tenantAdminEventsBase = "{$this->base_tenant_api_admin}events";
    }

    public function test_management_event_query_uses_single_bounded_occurrence_aggregate_and_bulk_occurrence_load(): void
    {
        $baseStart = Carbon::now()->startOfDay()->addDays(2)->setHour(10);
        for ($index = 0; $index < 12; $index++) {
            $this->createEventFixture(
                sprintf('Performance Guard Event %02d', $index),
                $baseStart->copy()->addDays($index)
            );
        }

        $aggregateCalls = [];
        $bulkLoadCalls = [];
        EventBus::listen(
            'belluga.events.management_occurrence_aggregate',
            static function (string $purpose, array $pipeline) use (&$aggregateCalls): void {
                $aggregateCalls[] = [
                    'purpose' => $purpose,
                    'pipeline' => $pipeline,
                ];
            }
        );
        EventBus::listen(
            'belluga.events.management_occurrence_bulk_load',
            static function (array $eventIds) use (&$bulkLoadCalls): void {
                $bulkLoadCalls[] = $eventIds;
            }
        );

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}?temporal=future&page=1&page_size=5"
        );

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data') ?? []);

        $this->assertCount(1, $aggregateCalls, 'Management occurrence pagination must execute one aggregate for count and page rows.');
        $this->assertSame('management_occurrence_page_with_count', $aggregateCalls[0]['purpose']);
        $this->assertTrue(
            $this->pipelineContainsStage($aggregateCalls[0]['pipeline'], '$facet'),
            'Management occurrence pagination must combine count and page rows through a single $facet pipeline.'
        );
        $this->assertTrue(
            $this->pipelineUsesIndexedEventLookup($aggregateCalls[0]['pipeline']),
            'Management occurrence pagination must use localField/foreignField event lookup, not string expression lookup.'
        );

        $this->assertCount(1, $bulkLoadCalls, 'Management formatter must bulk-load occurrences once for the page.');
        $this->assertCount(5, $bulkLoadCalls[0], 'Bulk occurrence formatter load must be bounded to the requested page size.');
    }

    public function test_management_event_query_source_does_not_reintroduce_all_occurrence_id_materialization(): void
    {
        $source = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventQueryService.php');

        $this->assertStringContainsString('runManagementOccurrenceAggregate', $source);
        $this->assertStringContainsString('loadOccurrencesByEventIds', $source);
        $this->assertStringNotContainsString('resolveManagementOccurrenceEventIds', $source);
        $this->assertStringNotContainsString("->pluck('event_id')", $source);
        $this->assertStringNotContainsString('formatManagementEvent($event));', $source);
    }

    /**
     * @param  array<int, mixed>  $pipeline
     */
    private function pipelineContainsStage(array $pipeline, string $stage): bool
    {
        foreach ($pipeline as $operation) {
            if (is_array($operation) && array_key_exists($stage, $operation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $pipeline
     */
    private function pipelineUsesIndexedEventLookup(array $pipeline): bool
    {
        foreach ($pipeline as $operation) {
            if (! is_array($operation) || ! isset($operation['$lookup']) || ! is_array($operation['$lookup'])) {
                continue;
            }

            $lookup = $operation['$lookup'];

            return ($lookup['from'] ?? null) === 'events'
                && ($lookup['localField'] ?? null) === 'event_object_id'
                && ($lookup['foreignField'] ?? null) === '_id'
                && ! array_key_exists('pipeline', $lookup);
        }

        return false;
    }

    private function createEventFixture(string $title, Carbon $start): Event
    {
        $event = Event::query()->create([
            'title' => $title,
            'content' => 'Performance guard content',
            'location' => ['mode' => 'physical'],
            'type' => [
                'id' => (string) $this->eventType->_id,
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
                'description' => (string) $this->eventType->description,
                'icon' => (string) $this->eventType->icon,
                'color' => (string) $this->eventType->color,
            ],
            'date_time_start' => $start,
            'date_time_end' => $start->copy()->addHours(2),
            'tags' => [],
            'categories' => [],
            'taxonomy_terms' => [],
            'event_parties' => [],
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute(),
            ],
            'is_active' => true,
        ]);

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [
            [
                'date_time_start' => $start,
                'date_time_end' => $start->copy()->addHours(2),
            ],
            [
                'date_time_start' => $start->copy()->addHours(3),
                'date_time_end' => $start->copy()->addHours(5),
            ],
        ]);

        return $event->fresh();
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
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
