<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Integration\Events\AccountProfileResolverAdapter;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\EventType;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Application\Events\EventOccurrenceNestedAccountStore;
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
        $this->assertStringNotContainsString("'account_context_ids' => \$accountContextId", $occurrenceQuerySource);
        $this->assertStringContainsString('event.created_by.id', $occurrenceQuerySource);
        $this->assertStringContainsString('event.created_by._id', $occurrenceQuerySource);
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

    public function test_public_physical_host_resolution_reuses_profile_type_reads(): void
    {
        $publicPoiType = TenantProfileType::query()->create([
            'type' => 'resolver-budget-venue',
            'label' => 'Resolver Budget Venue',
            'labels' => [
                'singular' => 'Resolver Budget Venue',
                'plural' => 'Resolver Budget Venues',
            ],
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_publicly_navigable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $account = Account::query()->create([
            'name' => 'Public Resolver Budget Account',
            'document' => 'DOC-PUBLIC-RESOLVER-BUDGET',
        ]);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => (string) $publicPoiType->type,
            'display_name' => 'Public Resolver Budget Venue',
            'slug' => 'public-resolver-budget-venue',
            'taxonomy_terms' => [],
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.1234, -20.5678],
            ],
            'visibility' => 'public',
            'is_active' => true,
        ]);

        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $resolved = app(AccountProfileResolverAdapter::class)
            ->resolveExistingPublicPhysicalHostsByProfileIds([(string) $profile->_id]);

        $queries = collect($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();
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

        $profileId = (string) $profile->_id;
        $this->assertArrayHasKey($profileId, $resolved);
        $this->assertSame(
            'Public Resolver Budget Venue',
            data_get($resolved, "{$profileId}.venue.display_name")
        );
        $this->assertSame(
            '/parceiro/public-resolver-budget-venue',
            data_get($resolved, "{$profileId}.venue.public_detail_path")
        );
        $this->assertLessThanOrEqual(
            3,
            $queries->count(),
            "Public physical host resolution must stay within the cold <=3 statement ceiling. Queries: {$queryLogJson}"
        );
        $this->assertCount(
            2,
            $profileTypeQueries,
            "Public physical host resolution must reuse a bounded pair of account_profile_types lookups. Queries: {$queryLogJson}"
        );
        $this->assertCount(
            1,
            $accountProfileQueries,
            "Public physical host resolution must fetch account_profiles once after type filtering. Queries: {$queryLogJson}"
        );
    }

    public function test_account_scoped_management_occurrence_query_filters_owned_events_without_legacy_account_context_snapshots(): void
    {
        $account = Account::query()->create([
            'name' => 'Scoped Performance Account',
            'document' => 'DOC-SCOPED-PERF',
        ]);
        $otherAccount = Account::query()->create([
            'name' => 'Other Performance Account',
            'document' => 'DOC-OTHER-PERF',
        ]);
        $scopedUser = $this->createAccountUserWithWildcardEventsRole($account, 'scoped-performance');
        $otherUser = $this->createAccountUserWithWildcardEventsRole($otherAccount, 'other-performance');

        $start = Carbon::now()->startOfDay()->addDays(2)->setHour(10);
        $this->createOwnedEventFixture(
            $account,
            $scopedUser,
            'Scoped Account Event',
            $start
        );
        $this->createOwnedEventFixture(
            $otherAccount,
            $otherUser,
            'Other Account Event',
            $start->copy()->addHour()
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
            true,
            (string) $account->_id
        );

        $this->assertSame(1, $paginator->total());
        $this->assertCount(1, $aggregateCalls);
        $pipeline = $aggregateCalls[0]['pipeline'];
        $this->assertSame('$match', array_key_first($pipeline[0]));
        $this->assertSame('$group', array_key_first($pipeline[1]));
        $this->assertTrue(
            $this->pipelineContainsStage($pipeline, '$lookup'),
            'Account-scoped management occurrence pagination must still resolve events through the indexed lookup pipeline.'
        );

        $firstMatch = $pipeline[0]['$match'] ?? [];
        $this->assertIsArray($firstMatch);
        $this->assertFalse(
            $this->arrayContainsScalar($firstMatch, 'account_context_ids'),
            'Initial occurrence $match must not depend on removed account_context_ids snapshots.'
        );
        $this->assertFalse(
            $this->arrayContainsScalar($firstMatch, 'event_parties'),
            'Initial occurrence $match must not depend on removed event_parties snapshots.'
        );

        $matchStages = array_values(array_filter(
            $pipeline,
            static fn (mixed $operation): bool => is_array($operation) && array_key_exists('$match', $operation)
        ));
        $finalMatch = $matchStages[array_key_last($matchStages)]['$match'] ?? [];
        $this->assertIsArray($finalMatch);
        $finalMatchJson = json_encode($finalMatch, JSON_UNESCAPED_SLASHES);
        $this->assertIsString($finalMatchJson);
        $this->assertTrue(
            str_contains($finalMatchJson, 'event.created_by.id'),
            'Account-scoped management occurrence pagination must scope owned events through event.created_by after the event lookup.'
        );
        $this->assertTrue(
            str_contains($finalMatchJson, (string) $scopedUser->_id),
            'Event ownership filter must include the scoped account-user id in the post-lookup event match.'
        );
        $this->assertFalse(
            str_contains($finalMatchJson, 'account_context_ids'),
            'Post-lookup event scoping must not reintroduce removed account_context_ids snapshots.'
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
        $connection->disableQueryLog();
        $connection->flushQueryLog();
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

    public function test_account_scoped_management_queries_use_owner_account_authority_without_legacy_account_context_fields(): void
    {
        $start = Carbon::now()->startOfDay()->addDays(3)->setHour(10);
        $account = Account::query()->create([
            'name' => 'Owner Scoped Account',
            'document' => 'DOC-OWNER-SCOPED',
        ]);
        $role = $account->roleTemplates()->create([
            'name' => 'Owner Scoped Events Role',
            'permissions' => ['*'],
        ]);
        $user = app(AccountUserService::class)->create($account, [
            'name' => 'Owner Scoped Events User',
            'email' => uniqid('owner-scoped-events-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);

        Sanctum::actingAs($user, ['events:create', 'events:read']);
        $createResponse = $this->postJson(
            "{$this->base_api_tenant}accounts/{$account->slug}/events",
            [
                'title' => 'Owner Scoped Online Event',
                'content' => 'Owner scoped online content',
                'location' => [
                    'mode' => 'online',
                    'online' => [
                        'url' => 'https://meet.example.org/owner-scope',
                        'platform' => 'jitsi',
                    ],
                ],
                'place_ref' => null,
                'type' => [
                    'id' => (string) $this->eventType->_id,
                    'name' => (string) $this->eventType->name,
                    'slug' => (string) $this->eventType->slug,
                    'description' => (string) $this->eventType->description,
                    'icon' => (string) $this->eventType->icon,
                    'color' => (string) $this->eventType->color,
                ],
                'occurrences' => [[
                    'date_time_start' => $start->copy()->toISOString(),
                    'date_time_end' => $start->copy()->addHours(2)->toISOString(),
                ]],
                'categories' => [],
                'taxonomy_terms' => [],
                'publication' => [
                    'status' => 'published',
                    'publish_at' => Carbon::now()->subMinute()->toISOString(),
                ],
            ]
        );
        $createResponse->assertStatus(201);

        $eventId = (string) $createResponse->json('data.event_id');
        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->firstOrFail();

        $this->assertEmpty($event->fresh()->account_context_ids ?? []);
        $this->assertEmpty($occurrence->account_context_ids ?? []);
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

        Sanctum::actingAs($user, ['events:read']);
        $listResponse = $this->getJson(
            "{$this->base_api_tenant}accounts/{$account->slug}/events?temporal=future&page=1&page_size=10"
        );

        $listResponse->assertStatus(200);
        $this->assertContains(
            $eventId,
            collect($listResponse->json('data') ?? [])
                ->pluck('event_id')
                ->map(static fn ($id): string => (string) $id)
                ->values()
                ->all()
        );
        $this->assertCount(1, $aggregateCalls);
        $this->assertSame('management_occurrence_page_with_count', $aggregateCalls[0]['purpose']);
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

    public function test_event_detail_and_management_readback_use_single_bounded_live_account_profile_hydration(): void
    {
        TenantProfileType::query()->whereIn('type', ['artist', 'band'])->delete();
        foreach ([
            ['type' => 'artist', 'label' => 'Artist'],
            ['type' => 'band', 'label' => 'Band'],
        ] as $type) {
            TenantProfileType::query()->create([
                'type' => $type['type'],
                'label' => $type['label'],
                'allowed_taxonomies' => [],
                'visual' => ['mode' => 'icon', 'icon' => 'store'],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => true,
                    'is_inviteable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_content' => false,
                ],
            ]);
        }

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

        $selectedOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->firstOrFail();
        $groupMetadata = [[
            'id' => 'artists',
            'label' => 'Artists',
            'order' => 0,
        ]];
        $selectedOccurrence->forceFill([
            'own_profile_groups' => $groupMetadata,
            'profile_groups' => $groupMetadata,
        ])->save();
        app(EventOccurrenceNestedAccountStore::class)->syncOccurrenceGroups(
            (string) $event->_id,
            $selectedOccurrence,
            [[
                'id' => 'artists',
                'label' => 'Artists',
                'order' => 0,
                'account_profile_ids' => $profiles
                    ->take(2)
                    ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
                    ->values()
                    ->all(),
            ]]
        );

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
        $connection->flushQueryLog();

        $this->assertSame((string) $event->_id, $detailPayload['event_id'] ?? null);
        $this->assertSame(2, $detailPayload['counterpart_count'] ?? null);
        $this->assertCount(1, $detailPayload['counterpart_preview'] ?? []);
        $this->assertSame(
            (string) $profiles->get(0)?->_id,
            data_get($detailPayload, 'counterpart_preview.0.id')
        );
        $detailAccountProfileQueries = $detailQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            1,
            $detailAccountProfileQueries,
            'Public event detail must use a single bounded live account profile hydration query keyed by stored counterpart summaries.'
        );

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $managementPayload = $service->formatManagementEvent($event->fresh());
        $managementQueries = collect($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $this->assertSame((string) $event->_id, $managementPayload['event_id'] ?? null);
        $managementAccountProfileQueries = $managementQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            1,
            $managementAccountProfileQueries,
            'Management event readback must use a single bounded live account profile hydration query during read formatting.'
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
        $event = $this->createEventFixture(
            'Performance Guard List Snapshot Event',
            Carbon::now()->startOfDay()->addDays(6)->setHour(10),
            []
        );
        $occurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->firstOrFail();
        $groupMetadata = [[
            'id' => 'artists',
            'label' => 'Artists',
            'order' => 0,
        ]];
        $occurrence->forceFill([
            'own_profile_groups' => $groupMetadata,
            'profile_groups' => $groupMetadata,
        ])->save();
        app(EventOccurrenceNestedAccountStore::class)->syncOccurrenceGroups(
            (string) $event->_id,
            $occurrence,
            [[
                'id' => 'artists',
                'label' => 'Artists',
                'order' => 0,
                'account_profile_ids' => $profiles
                    ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
                    ->values()
                    ->all(),
            ]]
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
        $connection->flushQueryLog();

        $this->assertNotEmpty($agendaPayload['items'] ?? []);
        $agendaAccountProfileQueries = $agendaQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            1,
            $agendaAccountProfileQueries,
            'Public agenda list formatting must use a single bounded live account profile hydration query.'
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
        $connection->flushQueryLog();

        $this->assertNotEmpty($managementPaginator->items());
        $managementAccountProfileQueries = $managementQueries->filter(
            static fn (array $query): bool => str_contains(
                json_encode($query, JSON_UNESCAPED_SLASHES),
                'account_profiles'
            )
        );
        $this->assertCount(
            1,
            $managementAccountProfileQueries,
            'Management event list formatting must use a single bounded live account profile hydration query.'
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

    private function createOwnedEventFixture(
        Account $account,
        mixed $user,
        string $title,
        Carbon $start,
    ): Event {
        Sanctum::actingAs($user, ['events:create', 'events:read']);

        $response = $this->postJson(
            "{$this->base_api_tenant}accounts/{$account->slug}/events",
            [
                'title' => $title,
                'content' => 'Performance guard content',
                'location' => [
                    'mode' => 'online',
                    'online' => [
                        'url' => 'https://meet.example.org/'.strtolower(str_replace(' ', '-', $title)),
                        'platform' => 'jitsi',
                    ],
                ],
                'place_ref' => null,
                'type' => [
                    'id' => (string) $this->eventType->_id,
                    'name' => (string) $this->eventType->name,
                    'slug' => (string) $this->eventType->slug,
                    'description' => (string) $this->eventType->description,
                    'icon' => (string) $this->eventType->icon,
                    'color' => (string) $this->eventType->color,
                ],
                'occurrences' => [[
                    'date_time_start' => $start->copy()->toISOString(),
                    'date_time_end' => $start->copy()->addHours(2)->toISOString(),
                ]],
                'categories' => [],
                'taxonomy_terms' => [],
                'publication' => [
                    'status' => 'published',
                    'publish_at' => Carbon::now()->subMinute()->toISOString(),
                ],
            ]
        );
        $response->assertCreated();

        return Event::query()->where('title', $title)->firstOrFail()->fresh();
    }

    private function createAccountUserWithWildcardEventsRole(Account $account, string $slug): mixed
    {
        $role = $account->roleTemplates()->create([
            'name' => sprintf('%s Events Role', ucfirst(str_replace('-', ' ', $slug))),
            'permissions' => ['*'],
        ]);

        return app(AccountUserService::class)->create($account, [
            'name' => sprintf('%s Events User', ucfirst(str_replace('-', ' ', $slug))),
            'email' => sprintf('%s-%s@example.org', $slug, uniqid('', true)),
            'password' => 'Secret!234',
        ], (string) $role->_id);
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
