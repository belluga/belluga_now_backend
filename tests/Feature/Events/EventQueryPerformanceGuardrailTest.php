<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        TaxonomyTerm::query()->delete();
        Taxonomy::query()->delete();

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
            $this->pipelineContainsStage($aggregateCalls[0]['pipeline'], '$lookup'),
            'Management occurrence pagination must include a single event lookup stage before its lookup shape is validated.'
        );
        $this->assertTrue(
            $this->pipelineUsesIndexedEventLookup($aggregateCalls[0]['pipeline']),
            'Management occurrence pagination must use localField/foreignField event lookup, not string expression lookup.'
        );
        $firstMatch = $aggregateCalls[0]['pipeline'][0]['$match'] ?? [];
        $this->assertArrayNotHasKey(
            '$expr',
            $firstMatch,
            'Future-only management occurrence filtering must use an index-friendly starts_at match instead of $expr.'
        );
        $this->assertArrayHasKey('starts_at', $firstMatch);
        $this->assertArrayHasKey('$gt', $firstMatch['starts_at']);

        $this->assertCount(1, $bulkLoadCalls, 'Management formatter must bulk-load occurrences once for the page.');
        $this->assertCount(5, $bulkLoadCalls[0], 'Bulk occurrence formatter load must be bounded to the requested page size.');
    }

    public function test_management_event_query_source_does_not_reintroduce_all_occurrence_id_materialization(): void
    {
        $source = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventQueryService.php');
        $occurrenceQuerySource = $this->readSource(
            'packages/belluga/belluga_events/src/Application/Events/EventManagementOccurrenceQuery.php'
        );

        $this->assertStringContainsString('paginateEventIds', $source);
        $this->assertStringContainsString('runAggregate', $occurrenceQuerySource);
        $this->assertStringContainsString('management_occurrence_page_with_count', $occurrenceQuerySource);
        $this->assertStringContainsString('loadOccurrencesByEventIds', $source);
        $this->assertStringNotContainsString('resolveManagementOccurrenceEventIds', $source);
        $this->assertStringNotContainsString("->pluck('event_id')", $source.$occurrenceQuerySource);
        $this->assertStringNotContainsString('listProfileIdsForAccount($accountContextId)', $occurrenceQuerySource);
        $this->assertStringContainsString("'account_context_ids' => \$accountContextId", $occurrenceQuerySource);
        $this->assertStringNotContainsString('formatManagementEvent($event));', $source);
    }

    public function test_event_query_service_exposes_only_surface_specific_formatters(): void
    {
        $source = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventQueryService.php');
        $reflection = new \ReflectionClass(EventQueryService::class);

        $this->assertFalse(
            $reflection->hasMethod('formatEvent'),
            'Generic formatEvent formatter must not exist; callers must choose an explicit read surface.'
        );
        $this->assertFalse(
            $reflection->hasMethod('formatEvents'),
            'Generic formatEvents formatter must not exist; callers must choose an explicit read surface.'
        );
        $this->assertStringNotContainsString('function formatEvent(', $source);
        $this->assertStringNotContainsString('function formatEvents(', $source);
        $this->assertTrue($reflection->hasMethod('formatMetadataEvent'));
        $this->assertTrue($reflection->hasMethod('formatManagementEvent'));
        $this->assertTrue($reflection->hasMethod('formatManagementEventList'));
        $this->assertTrue($reflection->hasMethod('formatAgendaEvents'));
    }

    public function test_public_agenda_geo_index_exists_for_published_occurrences(): void
    {
        $this->assertContains(
            'idx_event_occurrences_public_agenda_geo_v1',
            $this->indexNames('event_occurrences'),
            'Nearby Home agenda must narrow published, non-deleted occurrences with its dedicated geo index.'
        );
    }

    public function test_account_scoped_management_occurrence_query_filters_profile_snapshots_before_grouping(): void
    {
        $account = Account::query()->create([
            'name' => 'Scoped Performance Account',
            'document' => 'DOC-SCOPED-PERF',
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Scoped Performance Artist',
            'is_active' => true,
        ]);
        $otherAccount = Account::query()->create([
            'name' => 'Other Performance Account',
            'document' => 'DOC-OTHER-PERF',
        ]);
        $otherProfile = AccountProfile::query()->create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Other Performance Artist',
            'is_active' => true,
        ]);

        $start = Carbon::now()->startOfDay()->addDays(2)->setHour(10);
        $this->createEventFixture(
            'Scoped Account Event',
            $start,
            [$this->eventPartyForProfile($profile)]
        );
        $this->createEventFixture(
            'Other Account Event',
            $start->copy()->addHour(),
            [$this->eventPartyForProfile($otherProfile)]
        );

        $aggregateCalls = [];
        EventBus::listen(
            'belluga.events.management_occurrence_aggregate',
            static function (string $purpose, array $pipeline) use (&$aggregateCalls): void {
                $aggregateCalls[] = [
                    'purpose' => $purpose,
                    'pipeline' => $pipeline,
                ];
            }
        );

        $paginator = app(EventQueryService::class)->paginateManagement(
            ['temporal' => 'future', 'page' => 1, 'page_size' => 10],
            false,
            10,
            false,
            (string) $account->_id
        );

        $this->assertSame(1, $paginator->total());
        $this->assertCount(1, $aggregateCalls);
        $pipeline = $aggregateCalls[0]['pipeline'];
        $this->assertSame('$match', array_key_first($pipeline[0]));
        $this->assertSame('$group', array_key_first($pipeline[1]));

        $firstMatch = $pipeline[0]['$match'] ?? [];
        $this->assertIsArray($firstMatch);
        $this->assertArrayHasKey(
            '$and',
            $firstMatch,
            'Account-scoped occurrence queries must narrow by denormalized account context before grouping.'
        );
        $this->assertTrue(
            $this->arrayContainsScalar($firstMatch['$and'], (string) $account->_id),
            'Initial occurrence $match must contain the scoped account id before $group.'
        );
        $this->assertFalse(
            $this->arrayContainsScalar($firstMatch['$and'], (string) $profile->_id),
            'Account-scoped occurrence queries must not fan out into all profile ids before $group.'
        );
    }

    public function test_event_account_profile_candidates_physical_host_request_stays_within_cold_query_budget(): void
    {
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'venue'],
            [
                'label' => 'Venue',
                'labels' => [
                    'singular' => 'Venue',
                    'plural' => 'Venues',
                ],
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_discoverable' => true,
                    'is_publicly_navigable' => true,
                    'is_poi_enabled' => true,
                ],
            ]
        );

        foreach (range(1, 3) as $index) {
            $account = Account::query()->create([
                'name' => sprintf('Budget Venue Account %02d', $index),
                'document' => sprintf('DOC-BUDGET-VENUE-%02d', $index),
            ]);

            AccountProfile::query()->create([
                'account_id' => (string) $account->_id,
                'profile_type' => 'venue',
                'display_name' => sprintf('Budget Venue %02d', $index),
                'taxonomy_terms' => [],
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0 - ($index / 100), -20.0 - ($index / 100)],
                ],
                'is_active' => true,
                'is_verified' => false,
            ]);
        }

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, ['events:read']);

        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $response = $this->getJson(
            "{$this->tenantAdminEventsBase}/account_profile_candidates?type=physical_host&search=budget&page=1&page_size=2"
        );

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');

        $queries = collect($connection->getQueryLog());
        $queryLogJson = json_encode($queries->all(), JSON_UNESCAPED_SLASHES);
        $profileTypeQueries = $queries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profile_types'
            )
        );
        $accountProfileQueries = $queries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );

        $this->assertLessThanOrEqual(
            3,
            $queries->count(),
            "Candidate endpoint must stay within the cold <=3 statement ceiling. Queries: {$queryLogJson}"
        );
        $this->assertCount(
            1,
            $profileTypeQueries,
            "Candidate endpoint must resolve POI/queryable types with a single account_profile_types lookup. Queries: {$queryLogJson}"
        );
        $this->assertCount(
            2,
            $accountProfileQueries,
            "Candidate endpoint must issue exactly one count and one page-row account_profiles query. Queries: {$queryLogJson}"
        );
    }

    public function test_management_occurrence_query_intersects_specific_date_with_future_temporal_filter(): void
    {
        $pastStart = Carbon::now()->startOfDay()->subDays(2)->setHour(10);
        $this->createEventFixture('Past Dated Event', $pastStart);

        $paginator = app(EventQueryService::class)->paginateManagement(
            [
                'temporal' => 'future',
                'date' => $pastStart->toDateString(),
                'page' => 1,
                'page_size' => 10,
            ],
            false,
            10,
            true,
        );

        $this->assertSame(
            0,
            $paginator->total(),
            'A past specific date combined with temporal=future must be an intersection, not a date override.'
        );
    }

    public function test_account_context_backfill_migration_populates_legacy_events_occurrences_and_indexes_hot_queries(): void
    {
        $account = Account::query()->create([
            'name' => 'Legacy Context Account',
            'document' => 'DOC-LEGACY-CONTEXT',
        ]);
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => 'Legacy Context Artist',
            'is_active' => true,
        ]);
        $party = $this->eventPartyForProfile($profile);
        $start = Carbon::now()->startOfDay()->addDays(3)->setHour(10);

        $event = Event::query()->create([
            'title' => 'Legacy Account Context Event',
            'content' => 'Legacy account context content',
            'location' => ['mode' => 'physical'],
            'type' => [
                'id' => (string) $this->eventType->_id,
                'name' => (string) $this->eventType->name,
                'slug' => (string) $this->eventType->slug,
            ],
            'date_time_start' => $start,
            'date_time_end' => $start->copy()->addHours(2),
            'tags' => [],
            'categories' => [],
            'taxonomy_terms' => [],
            'event_parties' => [$party],
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute(),
            ],
            'is_active' => true,
        ]);

        EventOccurrence::query()->create([
            'event_id' => (string) $event->_id,
            'slug' => (string) $event->slug,
            'occurrence_slug' => 'legacy-account-context-event-0',
            'title' => (string) $event->title,
            'type' => $event->type,
            'event_parties' => [$party],
            'programming_items' => [[
                'time' => '10:30',
                'account_profile_ids' => [(string) $profile->_id],
            ]],
            'publication' => $event->publication,
            'is_event_published' => true,
            'is_active' => true,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'effective_ends_at' => $start->copy()->addHours(2),
        ]);

        $this->assertEmpty($event->fresh()->account_context_ids ?? []);
        $this->assertEmpty(EventOccurrence::query()->firstOrFail()->account_context_ids ?? []);

        $migration = require base_path(
            'packages/belluga/belluga_events/database/migrations/2026_04_25_000600_backfill_event_account_context_ids.php'
        );
        $migration->up();

        $this->assertContains((string) $account->_id, $event->fresh()->account_context_ids ?? []);
        $this->assertContains(
            (string) $account->_id,
            EventOccurrence::query()->where('event_id', (string) $event->_id)->firstOrFail()->account_context_ids ?? []
        );

        $paginator = app(EventQueryService::class)->paginateManagement(
            ['temporal' => 'future', 'page' => 1, 'page_size' => 10],
            false,
            10,
            false,
            (string) $account->_id
        );
        $this->assertSame(1, $paginator->total());

        $this->assertContains(
            'idx_events_account_context_management_v1',
            $this->indexNames('events')
        );
        $this->assertContains(
            'idx_event_occurrences_account_context_management_v1',
            $this->indexNames('event_occurrences')
        );
    }

    public function test_event_management_programming_resolution_uses_bulk_resolvers(): void
    {
        $managementSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventManagementService.php');
        $resolverSource = $this->readSource('app/Integration/Events/AccountProfileResolverAdapter.php');

        $this->assertStringContainsString('resolveProgrammingLinkedProfileMap(array_values($allProfileIds))', $managementSource);
        $this->assertStringContainsString('resolveProgrammingLocationProfileMap(', $managementSource);
        $this->assertStringContainsString('resolvePhysicalHostsByProfileIds(array_keys($placeRefsById))', $managementSource);
        $this->assertStringNotContainsString("'linked_account_profiles' => \$this->resolveProgrammingLinkedProfiles(\$profileIds)", $managementSource);
        $this->assertStringContainsString("->whereIn('_id', \$ids)", $resolverSource);
    }

    public function test_public_event_detail_reuses_preloaded_occurrences_for_selection_and_payload(): void
    {
        $event = $this->createEventFixture(
            'Performance Guard Detail Event',
            Carbon::now()->startOfDay()->addDays(3)->setHour(10)
        );
        $selectedOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->firstOrFail();

        $loads = [];
        EventBus::listen(
            'belluga.events.detail_occurrences_load',
            static function (string $eventId) use (&$loads): void {
                $loads[] = $eventId;
            }
        );

        $payload = app(EventQueryService::class)->formatEventDetail(
            $event->fresh(),
            null,
            (string) $selectedOccurrence->_id
        );

        $this->assertSame((string) $event->_id, $payload['event_id'] ?? null);
        $this->assertNotEmpty($payload['occurrences'] ?? []);
        $this->assertCount(
            1,
            $loads,
            'Event detail must load occurrences once and reuse the collection for selected occurrence and occurrences payload.'
        );
    }

    public function test_event_detail_and_management_readback_stay_snapshot_only_without_live_account_profiles_queries(): void
    {
        $profiles = collect([
            $this->createAccountProfileFixture('artist', 'Performance Linked Artist 01', 511),
            $this->createAccountProfileFixture('band', 'Performance Linked Band 02', 521),
            $this->createAccountProfileFixture('artist', 'Performance Linked Artist 03', 531),
            $this->createAccountProfileFixture('band', 'Performance Linked Band 04', 541),
        ]);
        $eventParties = $profiles
            ->map(fn (AccountProfile $profile): array => $this->eventPartyForProfile($profile))
            ->values()
            ->all();

        $event = $this->createEventFixture(
            'Performance Guard Live Profile Lookup Event',
            Carbon::now()->startOfDay()->addDays(4)->setHour(10),
            $eventParties
        );

        $groupPayload = [[
            'id' => 'artists',
            'label' => 'Artists',
            'order' => 0,
            'account_profile_ids' => $profiles
                ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
                ->values()
                ->all(),
        ]];

        foreach (EventOccurrence::query()->where('event_id', (string) $event->_id)->get() as $occurrence) {
            $occurrence->forceFill([
                'own_event_parties' => $eventParties,
                'own_linked_account_profiles' => array_map(
                    static fn (array $party): array => [
                        'id' => (string) ($party['party_ref_id'] ?? ''),
                        'display_name' => (string) data_get($party, 'metadata.display_name', ''),
                        'profile_type' => (string) data_get($party, 'metadata.profile_type', ''),
                    ],
                    array_slice($eventParties, 0, 2)
                ),
                'profile_groups' => $groupPayload,
            ])->save();
        }

        $selectedOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->skip(1)
            ->firstOrFail();

        $service = app(EventQueryService::class);
        $connection = DB::connection('tenant');

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $detailPayload = $service->formatEventDetail(
            $event->fresh(),
            null,
            (string) $selectedOccurrence->_id
        );
        $detailQueries = collect($connection->getQueryLog());
        $connection->disableQueryLog();

        $this->assertSame((string) $event->_id, $detailPayload['event_id'] ?? null);
        $detailAccountProfileQueries = $detailQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            0,
            $detailAccountProfileQueries,
            'Public event detail must stay snapshot-only and avoid live account profile queries during read formatting.'
        );

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $managementPayload = $service->formatManagementEvent($event->fresh());
        $managementQueries = collect($connection->getQueryLog());
        $connection->disableQueryLog();

        $this->assertSame((string) $event->_id, $managementPayload['event_id'] ?? null);
        $managementAccountProfileQueries = $managementQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            0,
            $managementAccountProfileQueries,
            'Management event readback must stay snapshot-only and avoid live account profile queries during read formatting.'
        );
        $managementOccurrenceQueries = $managementQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'event_occurrences'
            )
        );
        $this->assertCount(
            1,
            $managementOccurrenceQueries,
            'Management event readback must load event_occurrences once and reuse that collection across formatter steps.'
        );
    }

    public function test_agenda_and_management_list_paths_stay_snapshot_only_without_live_account_profiles_queries(): void
    {
        $profiles = collect([
            $this->createAccountProfileFixture('artist', 'Performance List Artist 01', 611),
            $this->createAccountProfileFixture('band', 'Performance List Band 02', 621),
        ]);
        $eventParties = $profiles
            ->map(fn (AccountProfile $profile): array => $this->eventPartyForProfile($profile))
            ->values()
            ->all();

        $this->createEventFixture(
            'Performance Guard List Snapshot Event',
            Carbon::now()->startOfDay()->addDays(6)->setHour(10),
            $eventParties
        );

        $service = app(EventQueryService::class);
        $connection = DB::connection('tenant');

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $agendaPayload = $service->fetchAgenda([
            'page' => 1,
            'page_size' => 10,
        ], null);
        $agendaQueries = collect($connection->getQueryLog());
        $connection->disableQueryLog();

        $this->assertNotEmpty($agendaPayload['items'] ?? []);
        $agendaAccountProfileQueries = $agendaQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            0,
            $agendaAccountProfileQueries,
            'Public agenda list formatting must stay snapshot-only and avoid live account profile queries.'
        );

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $managementPaginator = $service->paginateManagement(
            ['temporal' => 'future', 'page' => 1, 'page_size' => 10],
            false,
            10,
            true
        );
        $managementQueries = collect($connection->getQueryLog());
        $connection->disableQueryLog();

        $this->assertNotEmpty($managementPaginator->items());
        $managementAccountProfileQueries = $managementQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            0,
            $managementAccountProfileQueries,
            'Management event list formatting must stay snapshot-only and avoid live account profile queries.'
        );
    }

    public function test_taxonomy_snapshot_runtime_resolver_caches_legacy_term_resolution(): void
    {
        $taxonomy = Taxonomy::query()->create([
            'slug' => 'legacy_style',
            'name' => 'Legacy Style',
            'applies_to' => ['event'],
        ]);
        TaxonomyTerm::query()->create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'retro',
            'name' => 'Retro',
        ]);

        $taxonomyQueries = [];
        $termQueries = [];
        EventBus::listen(
            'belluga.taxonomy.summary_resolver_taxonomy_query',
            static function (array $slugs) use (&$taxonomyQueries): void {
                $taxonomyQueries[] = $slugs;
            }
        );
        EventBus::listen(
            'belluga.taxonomy.summary_resolver_terms_query',
            static function (string $taxonomyId, array $slugs) use (&$termQueries): void {
                $termQueries[] = [$taxonomyId, $slugs];
            }
        );

        $resolver = app(TaxonomyTermSummaryResolverService::class);
        $legacyTerms = [[
            'type' => 'legacy_style',
            'value' => 'retro',
        ]];

        foreach (range(1, 5) as $_) {
            $snapshots = $resolver->ensureSnapshots($legacyTerms);
            $this->assertSame('Retro', $snapshots[0]['name'] ?? null);
            $this->assertSame('Legacy Style', $snapshots[0]['taxonomy_name'] ?? null);
        }

        $this->assertCount(1, $taxonomyQueries, 'Runtime legacy taxonomy resolution must be cached per resolver instance.');
        $this->assertCount(1, $termQueries, 'Runtime legacy taxonomy term resolution must be cached per resolver instance.');
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

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     */
    private function createEventFixture(string $title, Carbon $start, array $eventParties = []): Event
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
            'event_parties' => $eventParties,
            'account_context_ids' => $this->accountContextIdsForParties($eventParties),
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

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, string>
     */
    private function accountContextIdsForParties(array $eventParties): array
    {
        $profileIds = collect($eventParties)
            ->map(static fn (array $party): string => trim((string) ($party['party_ref_id'] ?? '')))
            ->filter(static fn (string $profileId): bool => $profileId !== '')
            ->unique()
            ->values()
            ->all();

        if ($profileIds === []) {
            return [];
        }

        return AccountProfile::query()
            ->whereIn('_id', $profileIds)
            ->get(['account_id'])
            ->map(static fn (AccountProfile $profile): string => trim((string) ($profile->account_id ?? '')))
            ->filter(static fn (string $accountId): bool => $accountId !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPartyForProfile(AccountProfile $profile): array
    {
        return [
            'party_type' => (string) $profile->profile_type,
            'party_ref_id' => (string) $profile->_id,
            'metadata' => [
                'display_name' => (string) $profile->display_name,
                'slug' => (string) $profile->slug,
                'profile_type' => (string) $profile->profile_type,
                'avatar_url' => null,
                'cover_url' => null,
                'taxonomy_terms' => [],
            ],
            'permissions' => [
                'can_edit' => false,
                'is_visible' => true,
            ],
        ];
    }

    private function createAccountProfileFixture(
        string $profileType,
        string $displayName,
        int $versionSeed,
    ): AccountProfile {
        $account = Account::query()->create([
            'name' => $displayName.' Account',
            'document' => 'DOC-'.uniqid('perf-', true),
        ]);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => $profileType,
            'display_name' => $displayName,
            'slug' => strtolower(str_replace(' ', '-', $displayName)).'-'.uniqid(),
            'taxonomy_terms' => [],
            'is_active' => true,
        ]);

        $profile->avatar_url = sprintf(
            '/api/v1/media/account-profiles/%s/avatar?v=%d',
            $profile->_id,
            $versionSeed
        );
        $profile->cover_url = sprintf(
            '/api/v1/media/account-profiles/%s/cover?v=%d',
            $profile->_id,
            $versionSeed + 1
        );
        $profile->save();

        return $profile->fresh();
    }

    private function arrayContainsScalar(mixed $value, string $needle): bool
    {
        if ($value === $needle) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->arrayContainsScalar($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function indexNames(string $collection): array
    {
        $names = [];
        foreach (DB::connection('tenant')->getCollection($collection)->listIndexes() as $index) {
            $names[] = (string) $index->getName();
        }

        return $names;
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
