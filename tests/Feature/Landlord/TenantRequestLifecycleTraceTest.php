<?php

declare(strict_types=1);

namespace Tests\Feature\Landlord;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Environment\EnvironmentResolverService;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\Helpers\TenantLabels;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class TenantRequestLifecycleTraceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private string $defaultConnectionAtRest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();

        $this->defaultConnectionAtRest = (string) config('database.default', 'mongodb');
        $this->normalizeTenantRuntimeState();
    }

    public function test_environment_request_trace_records_resolution_switch_queries_and_cleanup(): void
    {
        $tenant = $this->primaryTenant();
        $this->seedEnvironmentSnapshot($tenant);
        $landlordConnection = (string) config('multitenancy.landlord_database_connection_name', 'landlord');
        $tenantConnection = $this->tenantConnectionName();

        $response = $this->getJson($this->environmentUrlForHost($this->tenantHost($tenant)), $this->traceHeaders());

        $response->assertOk();
        $response->assertJson([
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
        ]);

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $stages = array_column($trace['events'], 'stage');

        $this->assertStageSequence($stages, [
            'request.started',
            'finder.branch.subdomain',
            'resolver.subdomain.started',
            'resolver.subdomain.resolved',
            'tenant.matched',
            'tenant.switch.start',
            'tenant.switch.connection_configured',
            'tenant.switch.connection_purged',
            'tenant.switch.default_connection_selected',
            'tenant.switch.complete',
            'endpoint.environment.controller.enter',
            'environment.snapshot.lookup.start',
            "mongo.first.{$tenantConnection}",
            'environment.snapshot.lookup.loaded',
            'environment.snapshot.hydrate.start',
            'environment.payload.derive.start',
            'environment.payload.branding_assets.start',
            'environment.payload.branding_assets.complete',
            'environment.payload.derive.complete',
            'environment.snapshot.hydrate.complete',
            'endpoint.environment.response_ready',
            'request.response_handoff',
            'request.cleanup.start',
            'tenant.forget.start',
            'tenant.forget.connection_purged',
            'tenant.forget.default_restored',
            'tenant.forget.complete',
            'request.cleanup.complete',
        ]);
        $this->assertStageOccursBetween(
            $stages,
            "mongo.first.{$landlordConnection}",
            'resolver.subdomain.started',
            'resolver.subdomain.resolved',
        );
        $this->assertStageOccursBetween(
            $stages,
            "mongo.first.{$tenantConnection}",
            'endpoint.environment.controller.enter',
            'endpoint.environment.response_ready',
        );
        $this->assertCount(
            1,
            $this->mongoCollectionCommandsBetween(
                $trace['events'],
                'endpoint.environment.controller.enter',
                'endpoint.environment.response_ready',
                $tenantConnection,
            ),
            'Tenant environment response should issue exactly one collection-backed tenant command after controller entry.',
        );
        $this->assertCount(
            0,
            $this->mongoCollectionCommandsBetween(
                $trace['events'],
                'endpoint.environment.controller.enter',
                'endpoint.environment.response_ready',
                $landlordConnection,
            ),
            'Tenant environment response should not re-read landlord-side tenant metadata once the snapshot-backed request context is already resolved.',
        );

        $this->assertSame(
            $this->traceRecorder()->tenantFingerprint($tenant),
            $this->firstEventValue($trace['events'], 'tenant.matched', 'tenant_target')
        );
        $this->assertTenantRuntimeReset();
    }

    public function test_public_shell_root_trace_records_bounded_metadata_queries_after_tenancy_switch(): void
    {
        $tenant = $this->primaryTenant();
        $landlordConnection = (string) config('multitenancy.landlord_database_connection_name', 'landlord');
        $fixturePath = realpath(__DIR__.'/../../Fixtures/PublicWeb/flutter_shell_index.html');

        $this->assertIsString($fixturePath);

        $previousShellPath = (string) getenv('FLUTTER_WEB_SHELL_PATH');
        putenv("FLUTTER_WEB_SHELL_PATH={$fixturePath}");

        try {
            $response = $this->withHeaders($this->traceHeaders())
                ->get($this->webUrlForHost($this->tenantHost($tenant)));
        } finally {
            putenv('FLUTTER_WEB_SHELL_PATH='.$previousShellPath);
        }

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $stages = array_column($trace['events'], 'stage');

        $this->assertStageSequence($stages, [
            'tenant.switch.complete',
            'endpoint.public_shell.controller.enter',
            'endpoint.public_shell.response_ready',
            'request.response_handoff',
        ]);
        $this->assertCount(
            0,
            $this->mongoCollectionCommandsBetween(
                $trace['events'],
                'endpoint.public_shell.controller.enter',
                'endpoint.public_shell.response_ready',
                $this->tenantConnectionName(),
            ),
            'Public shell fallback should not re-read the tenant after tenancy middleware has resolved it.',
        );
        $this->assertCount(
            1,
            $this->mongoCollectionCommandsBetween(
                $trace['events'],
                'endpoint.public_shell.controller.enter',
                'endpoint.public_shell.response_ready',
                $landlordConnection,
            ),
            'Public shell fallback should issue at most one collection-backed landlord read after controller entry.',
        );

        $this->assertTenantRuntimeReset();
    }

    public function test_landlord_well_known_fallback_runs_after_clean_tenant_runtime_reset(): void
    {
        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'app_links' => [
                'android' => [
                    'package_name' => 'com.belluga.lifecycle',
                    'sha256_cert_fingerprints' => [
                        '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF',
                    ],
                ],
            ],
        ]);

        $tenant = $this->primaryTenant();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::query()->create([
            'app_links' => [
                'android' => [
                    'package_name' => 'com.belluga.tenant-lifecycle',
                    'sha256_cert_fingerprints' => [
                        'FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00:FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00',
                    ],
                ],
            ],
        ]);

        $tenantResponse = $this->withHeaders($this->traceHeaders())
            ->get($this->webUrlForHost($this->tenantHost($tenant), '/.well-known/assetlinks.json'));

        $tenantResponse->assertOk();
        $tenantResponse->assertJsonPath('0.target.package_name', 'com.belluga.tenant-lifecycle');

        $tenantConnectionName = $this->tenantConnectionName();
        $originalTenantDsn = config("database.connections.{$tenantConnectionName}.dsn");
        $tenantDsnWithoutDatabase = preg_replace(
            '#/[^/?]*(?:\\?.*)?$#',
            '/',
            (string) $originalTenantDsn,
        );
        $this->assertIsString($tenantDsnWithoutDatabase);

        config([
            "database.connections.{$tenantConnectionName}.database" => null,
            "database.connections.{$tenantConnectionName}.dsn" => $tenantDsnWithoutDatabase,
        ]);
        DB::purge($tenantConnectionName);

        try {
            $response = $this->withHeaders($this->traceHeaders())
                ->get($this->webUrlForHost($this->host, '/.well-known/assetlinks.json'));
        } finally {
            config([
                "database.connections.{$tenantConnectionName}.dsn" => $originalTenantDsn,
            ]);
            DB::purge($tenantConnectionName);
        }

        $response->assertOk();
        $response->assertJsonPath('0.target.package_name', 'com.belluga.lifecycle');

        $trace = $this->responseTrace($response);
        $stages = array_column($trace['events'], 'stage');
        $this->assertContains('tenant.not_found', $stages);
        $this->assertNotContains('mongo.first.'.$tenantConnectionName, $stages);
        $this->assertCount(
            0,
            array_filter(
                $trace['events'],
                fn (array $event): bool => ($event['stage'] ?? null) === 'mongo.command.'.$tenantConnectionName
                    && is_string($event['collection'] ?? null)
                    && trim((string) $event['collection']) !== '',
            ),
            'A landlord well-known request must not query the tenant connection.',
        );
        $this->assertSame(
            $this->defaultConnectionAtRest,
            $this->firstEventValue($trace['events'], 'tenant.not_found', 'default_connection'),
        );
        $this->assertNull($this->firstEventValue($trace['events'], 'tenant.not_found', 'tenant_current'));
        $this->assertTenantRuntimeReset();
    }

    public function test_current_tenant_without_app_links_does_not_use_landlord_well_known_settings(): void
    {
        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'app_links' => [
                'android' => [
                    'package_name' => 'com.belluga.landlord-fallback',
                    'sha256_cert_fingerprints' => [
                        '11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00',
                    ],
                ],
            ],
        ]);

        $tenant = $this->primaryTenant();
        $tenant->makeCurrent();
        TenantSettings::query()->updateOrCreate(
            ['_id' => TenantSettings::ROOT_ID],
            ['app_links' => []],
        );

        $response = $this->withHeaders($this->traceHeaders())
            ->get($this->webUrlForHost($this->tenantHost($tenant), '/.well-known/assetlinks.json'));

        $response->assertOk();
        $response->assertExactJson([]);
        $trace = $this->responseTrace($response);
        $stages = array_column($trace['events'], 'stage');
        $this->assertContains('tenant.matched', $stages);
        $this->assertSame(
            $this->traceRecorder()->tenantFingerprint($tenant),
            $this->firstEventValue($trace['events'], 'tenant.matched', 'tenant_target'),
        );
        $this->assertTenantRuntimeReset();
    }

    public function test_environment_request_trace_on_explicit_web_domain_skips_subdomain_probe(): void
    {
        $tenant = $this->primaryTenant();
        $this->seedEnvironmentSnapshot($tenant);
        $landlordConnection = (string) config('multitenancy.landlord_database_connection_name', 'landlord');
        $tenantConnection = $this->tenantConnectionName();
        $explicitHost = parse_url($tenant->getMainDomain(), PHP_URL_HOST);

        $this->assertIsString($explicitHost);

        $response = $this->getJson($this->environmentUrlForHost($explicitHost), $this->traceHeaders());

        $response->assertOk();
        $response->assertJson([
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
        ]);

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $stages = array_column($trace['events'], 'stage');

        $this->assertNotContains('finder.branch.subdomain', $stages);
        $this->assertNotContains('resolver.subdomain.started', $stages);
        $this->assertNotContains('resolver.web_domain.fallback.started', $stages);
        $this->assertStageSequence($stages, [
            'request.started',
            'finder.branch.web_domain',
            'resolver.web_domain.primary.started',
            'resolver.web_domain.primary.resolved',
            'tenant.matched',
            'tenant.switch.start',
            'tenant.switch.connection_configured',
            'tenant.switch.connection_purged',
            'tenant.switch.default_connection_selected',
            'tenant.switch.complete',
            'endpoint.environment.controller.enter',
            'environment.snapshot.lookup.start',
            "mongo.first.{$tenantConnection}",
            'environment.snapshot.lookup.loaded',
            'environment.snapshot.hydrate.start',
            'environment.payload.derive.start',
            'environment.payload.branding_assets.start',
            'environment.payload.branding_assets.complete',
            'environment.payload.derive.complete',
            'environment.snapshot.hydrate.complete',
            'endpoint.environment.response_ready',
            'request.response_handoff',
            'request.cleanup.start',
            'tenant.forget.start',
            'tenant.forget.connection_purged',
            'tenant.forget.default_restored',
            'tenant.forget.complete',
            'request.cleanup.complete',
        ]);
        $this->assertCount(
            2,
            $this->mongoCollectionCommandsBetween(
                $trace['events'],
                'resolver.web_domain.primary.started',
                'resolver.web_domain.primary.resolved',
                $landlordConnection,
            ),
            'Explicit web-domain resolution should use the canonical domains path plus one tenant hydration, without subdomain or fallback probes.',
        );

        $this->assertTenantRuntimeReset();
    }

    public function test_anonymous_identity_trace_records_controller_stage_markers(): void
    {
        $tenant = $this->primaryTenant();

        $response = $this->postJson(
            $this->apiUrlForHost($this->tenantHost($tenant), 'anonymous/identities'),
            [
                'device_name' => 'tenant-trace-anon-device',
                'fingerprint' => [
                    'hash' => hash('sha256', 'tenant-trace-anon-device'),
                    'user_agent' => 'LifecycleTraceTest/1.0',
                    'locale' => 'en-US',
                ],
                'metadata' => [
                    'source' => 'lifecycle-trace-test',
                ],
            ],
            $this->traceHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.identity_state', 'anonymous');

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $stages = array_column($trace['events'], 'stage');

        $this->assertStageSequence($stages, [
            'tenant.switch.complete',
            'endpoint.anonymous_identity.controller.enter',
            'endpoint.anonymous_identity.identity_registered',
            'endpoint.anonymous_identity.response_ready',
            'request.response_handoff',
        ]);
        $this->assertStageOccursBetween(
            $stages,
            'mongo.first.tenant',
            'endpoint.anonymous_identity.controller.enter',
            'endpoint.anonymous_identity.response_ready',
        );
        $this->assertSame(
            'anonymous',
            $this->firstEventValue($trace['events'], 'endpoint.anonymous_identity.identity_registered', 'identity_state')
        );

        $this->assertTenantRuntimeReset();
    }

    public function test_authenticated_tenant_reads_record_controller_stage_markers(): void
    {
        $tenant = $this->primaryTenant();
        $token = $this->issueTenantTraceToken();

        $me = $this->getJson(
            $this->apiUrlForHost($this->tenantHost($tenant), 'me'),
            [...$this->traceHeaders(), 'Authorization' => "Bearer {$token}"]
        );
        $me->assertOk();

        $meTrace = $this->completedTrace();
        $this->assertSame($meTrace, $this->responseTrace($me));
        $meStages = array_column($meTrace['events'], 'stage');

        $this->assertStageSequence($meStages, [
            'tenant.switch.complete',
            'middleware.auth.start',
            'middleware.auth.token_lookup.start',
            'middleware.auth.token_lookup.resolved',
            'middleware.auth.principal_hydration.start',
            'middleware.auth.principal_hydration.resolved',
            'middleware.auth.current_token_binding.start',
            'middleware.auth.current_token_binding.passed',
            'middleware.auth.last_used_at.start',
            'middleware.auth.last_used_at.passed',
            'middleware.auth.passed',
            'middleware.tenant_access.enter',
            'middleware.tenant_access.passed',
            'endpoint.me.controller.enter',
            'endpoint.me.response_ready',
            'request.response_handoff',
        ]);
        $this->assertContains('mongo.first.tenant', $meStages);
        $this->assertSame(
            'tenant_account_user',
            $this->firstEventValue($meTrace['events'], 'middleware.auth.passed', 'principal_kind')
        );
        $this->assertTrue(
            (bool) $this->firstEventValue($meTrace['events'], 'middleware.auth.last_used_at.passed', 'write_performed')
        );
        $this->assertTenantRuntimeReset();

        $agenda = $this->getJson(
            $this->apiUrlForHost($this->tenantHost($tenant), 'agenda?page=1&page_size=10'),
            [...$this->traceHeaders(), 'Authorization' => "Bearer {$token}"]
        );
        $agenda->assertOk();

        $agendaTrace = $this->completedTrace();
        $this->assertSame($agendaTrace, $this->responseTrace($agenda));
        $agendaStages = array_column($agendaTrace['events'], 'stage');

        $this->assertStageSequence($agendaStages, [
            'tenant.switch.complete',
            'middleware.auth.start',
            'middleware.auth.token_lookup.start',
            'middleware.auth.token_lookup.resolved',
            'middleware.auth.principal_hydration.start',
            'middleware.auth.principal_hydration.resolved',
            'middleware.auth.current_token_binding.start',
            'middleware.auth.current_token_binding.passed',
            'middleware.auth.last_used_at.start',
            'middleware.auth.last_used_at.passed',
            'middleware.auth.passed',
            'middleware.tenant_access.enter',
            'middleware.tenant_access.passed',
            'endpoint.agenda.controller.enter',
            'endpoint.agenda.payload_ready',
            'endpoint.agenda.response_ready',
            'request.response_handoff',
        ]);
        $this->assertContains('mongo.first.tenant', $agendaStages);
        $this->assertSame(
            'tenant_account_user',
            $this->firstEventValue($agendaTrace['events'], 'middleware.auth.passed', 'principal_kind')
        );
        $this->assertFalse(
            (bool) $this->firstEventValue($agendaTrace['events'], 'middleware.auth.last_used_at.passed', 'write_performed')
        );

        $this->assertTenantRuntimeReset();
    }

    public function test_anonymous_agenda_trace_requires_endpoint_local_attribution_boundaries(): void
    {
        $tenant = $this->primaryTenant();
        $event = $this->createPublishedFutureGeoAgendaEvent($tenant);

        $identity = $this->postJson(
            $this->apiUrlForHost($this->tenantHost($tenant), 'anonymous/identities'),
            [
                'device_name' => 'tenant-trace-agenda-device',
                'fingerprint' => [
                    'hash' => hash('sha256', 'tenant-trace-agenda-device'),
                    'user_agent' => 'LifecycleTraceTest/1.0',
                    'locale' => 'en-US',
                ],
                'metadata' => [
                    'source' => 'lifecycle-trace-agenda-test',
                ],
            ],
            ['Accept' => 'application/json'],
        );

        $identity->assertStatus(201);
        $token = (string) $identity->json('data.token');
        $this->assertNotSame('', trim($token));

        $agenda = $this->getJson(
            $this->apiUrlForHost(
                $this->tenantHost($tenant),
                'agenda?page=1&confirmed_only=0&past_only=0&origin_lat=-20.671339&origin_lng=-40.495395&max_distance_meters=50000',
            ),
            [...$this->traceHeaders(), 'Authorization' => "Bearer {$token}"],
        );

        $agenda->assertOk();
        $eventIds = array_map(
            static fn (mixed $item): string => (string) (is_array($item) ? ($item['event_id'] ?? '') : ''),
            $agenda->json('items') ?? [],
        );
        $this->assertContains((string) $event->_id, $eventIds);
        $agenda->assertJsonPath('discovery_filter_facets.surface', 'home.events');
        $agenda->assertJsonPath('discovery_filter_catalog.surface', 'home.events');

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($agenda));

        $stages = array_column($trace['events'], 'stage');

        $this->assertStageSequence($stages, [
            'tenant.switch.complete',
            'middleware.auth.start',
            'middleware.auth.token_lookup.start',
            'middleware.auth.token_lookup.resolved',
            'middleware.auth.principal_hydration.start',
            'middleware.auth.principal_hydration.resolved',
            'middleware.auth.current_token_binding.start',
            'middleware.auth.current_token_binding.passed',
            'middleware.auth.last_used_at.start',
            'middleware.auth.last_used_at.passed',
            'middleware.auth.passed',
            'middleware.tenant_access.enter',
            'middleware.tenant_access.passed',
            'endpoint.agenda.controller.enter',
            'endpoint.agenda.payload_ready',
            'endpoint.agenda.response_ready',
            'request.response_handoff',
        ]);
        $this->assertSame(
            'tenant_account_user',
            $this->firstEventValue($trace['events'], 'middleware.auth.passed', 'principal_kind'),
        );

        $this->assertStageSequence($stages, [
            'endpoint.agenda.controller.enter',
            'endpoint.agenda.aggregate.start',
            'endpoint.agenda.aggregate.complete',
            'endpoint.agenda.selection_catalog.start',
            'endpoint.agenda.selection_catalog.complete',
            'endpoint.agenda.hydration.start',
            'endpoint.agenda.hydration.complete',
            'endpoint.agenda.payload_ready',
            'endpoint.agenda.response_catalog.start',
            'endpoint.agenda.response_catalog.complete',
            'endpoint.agenda.response_ready',
        ]);
        $this->assertCount(1, array_keys($stages, 'endpoint.agenda.aggregate.start'));
        $this->assertCount(1, array_keys($stages, 'endpoint.agenda.aggregate.complete'));
        $this->assertContains(
            'event_occurrences',
            array_column(
                $this->mongoCollectionCommandsBetween(
                    $trace['events'],
                    'endpoint.agenda.aggregate.start',
                    'endpoint.agenda.aggregate.complete',
                    $this->tenantConnectionName(),
                ),
                'collection',
            ),
        );

        $this->assertTenantRuntimeReset();
    }

    public function test_same_process_environment_sequence_does_not_leak_tenant_context_between_hosts(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $sequence = [
            [$this->tenantHost($primaryTenant), 200, 'tenant', $primaryTenant],
            [$this->tenantHost($primaryTenant), 200, 'tenant', $primaryTenant],
            ["unknown.{$this->host}", 404, null, null],
            [$this->tenantHost($secondaryTenant), 200, 'tenant', $secondaryTenant],
            [$this->tenantHost($primaryTenant), 200, 'tenant', $primaryTenant],
        ];

        foreach ($sequence as [$host, $expectedStatus, $expectedType, $expectedTenant]) {
            $response = $this->getJson($this->environmentUrlForHost($host), $this->traceHeaders());

            $response->assertStatus($expectedStatus);

            $trace = $this->completedTrace();
            $this->assertSame($trace, $this->responseTrace($response));

            $stages = array_column($trace['events'], 'stage');

            if ($expectedTenant instanceof Tenant) {
                $response->assertJson(['type' => $expectedType, 'subdomain' => $expectedTenant->subdomain]);
                $this->assertContains('tenant.matched', $stages);
                $this->assertSame(
                    $this->traceRecorder()->tenantFingerprint($expectedTenant),
                    $this->firstEventValue($trace['events'], 'tenant.matched', 'tenant_target')
                );
            } else {
                $this->assertContains('tenant.not_found', $stages);
                $this->assertNotContains('tenant.matched', $stages);
            }

            $this->assertContains('request.cleanup.complete', $stages);
            $this->assertTenantRuntimeReset();
        }
    }

    public function test_cleanup_runs_even_when_environment_resolution_throws(): void
    {
        $tenant = $this->primaryTenant();

        $this->mock(EnvironmentResolverService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->andThrow(new \RuntimeException('forced environment failure'));
        });

        $response = $this->getJson($this->environmentUrlForHost($this->tenantHost($tenant)), $this->traceHeaders());

        $response->assertStatus(500);

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $stages = array_column($trace['events'], 'stage');

        $this->assertContains('tenant.switch.complete', $stages);
        $this->assertContains('request.response_handoff', $stages);
        $this->assertSame(500, $this->firstEventValue($trace['events'], 'request.response_handoff', 'status'));
        $this->assertStageSequence($stages, [
            'tenant.switch.complete',
            'request.response_handoff',
            'request.cleanup.start',
            'tenant.forget.start',
            'tenant.forget.connection_purged',
            'tenant.forget.default_restored',
            'tenant.forget.complete',
            'request.cleanup.complete',
        ]);

        $this->assertTenantRuntimeReset();
    }

    public function test_dirty_runtime_is_reset_before_request_resolution_runs(): void
    {
        $tenant = $this->primaryTenant();
        $tenant->makeCurrent();

        $response = $this->getJson($this->environmentUrlForHost($this->tenantHost($tenant)), $this->traceHeaders());

        $response->assertOk();
        $response->assertJson([
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
        ]);

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $events = $trace['events'];
        $stages = array_column($events, 'stage');

        $this->assertSame($this->defaultConnectionAtRest, $events[0]['default_connection'] ?? null);
        $this->assertArrayNotHasKey('tenant_current', $events[0]);
        $this->assertContains('request.bootstrap_reset', $stages);
        $this->assertSame(
            $this->tenantConnectionName(),
            $this->firstEventValue($events, 'request.bootstrap_reset', 'bootstrap_default_connection')
        );
        $this->assertSame(
            $this->traceRecorder()->tenantFingerprint($tenant),
            $this->firstEventValue($events, 'request.bootstrap_reset', 'bootstrap_tenant_current')
        );

        $this->assertStageSequence($stages, [
            'request.started',
            'request.bootstrap_reset',
            'tenant.switch.start',
            'tenant.switch.complete',
            'request.cleanup.start',
            'tenant.forget.complete',
            'request.cleanup.complete',
        ]);

        $this->assertTenantRuntimeReset();
    }

    public function test_runtime_with_only_tenant_database_residue_is_reset_before_request_resolution_runs(): void
    {
        $tenant = $this->primaryTenant();

        config([
            sprintf('database.connections.%s.database', $this->tenantConnectionName()) => 'tenant_residual_only',
        ]);

        $response = $this->getJson($this->environmentUrlForHost($this->tenantHost($tenant)), $this->traceHeaders());

        $response->assertOk();
        $response->assertJson([
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
        ]);

        $trace = $this->completedTrace();
        $this->assertSame($trace, $this->responseTrace($response));

        $events = $trace['events'];
        $stages = array_column($events, 'stage');

        $this->assertSame($this->defaultConnectionAtRest, $events[0]['default_connection'] ?? null);
        $this->assertArrayNotHasKey('tenant_current', $events[0]);
        $this->assertContains('request.bootstrap_reset', $stages);
        $this->assertSame(
            $this->defaultConnectionAtRest,
            $this->firstEventValue($events, 'request.bootstrap_reset', 'bootstrap_default_connection')
        );
        $this->assertTrue(
            (bool) $this->firstEventValue($events, 'request.bootstrap_reset', 'bootstrap_tenant_database_present')
        );

        $this->assertStageSequence($stages, [
            'request.started',
            'request.bootstrap_reset',
            'tenant.switch.start',
            'tenant.switch.complete',
            'request.cleanup.start',
            'tenant.forget.complete',
            'request.cleanup.complete',
        ]);

        $this->assertTenantRuntimeReset();
    }

    public function test_cleanup_restores_the_configured_baseline_default_connection(): void
    {
        $tenant = $this->primaryTenant();

        config(['database.default' => 'landlord']);
        $this->defaultConnectionAtRest = 'landlord';
        $this->normalizeTenantRuntimeState();

        $tenant->makeCurrent();

        $response = $this->getJson($this->environmentUrlForHost($this->tenantHost($tenant)), $this->traceHeaders());

        $response->assertOk();

        $this->assertTenantRuntimeReset();
    }

    /**
     * @param  list<string>  $actualStages
     * @param  list<string>  $expectedStages
     */
    private function assertStageSequence(array $actualStages, array $expectedStages): void
    {
        $offset = 0;

        foreach ($expectedStages as $expectedStage) {
            $position = array_search($expectedStage, array_slice($actualStages, $offset), true);

            $this->assertNotFalse(
                $position,
                sprintf('Expected lifecycle stage [%s] in ordered trace: %s', $expectedStage, implode(', ', $actualStages)),
            );

            $offset += $position + 1;
        }
    }

    /**
     * @param  list<string>  $actualStages
     */
    private function assertStageOccursBetween(
        array $actualStages,
        string $expectedStage,
        string $afterStage,
        string $beforeStage,
    ): void {
        $expectedIndex = array_search($expectedStage, $actualStages, true);
        $afterIndex = array_search($afterStage, $actualStages, true);
        $beforeIndex = array_search($beforeStage, $actualStages, true);

        $this->assertNotFalse($expectedIndex, sprintf('Expected lifecycle stage [%s] in trace: %s', $expectedStage, implode(', ', $actualStages)));
        $this->assertNotFalse($afterIndex, sprintf('Expected anchor lifecycle stage [%s] in trace: %s', $afterStage, implode(', ', $actualStages)));
        $this->assertNotFalse($beforeIndex, sprintf('Expected anchor lifecycle stage [%s] in trace: %s', $beforeStage, implode(', ', $actualStages)));
        $this->assertGreaterThan($afterIndex, $expectedIndex, sprintf('Expected lifecycle stage [%s] after [%s].', $expectedStage, $afterStage));
        $this->assertLessThan($beforeIndex, $expectedIndex, sprintf('Expected lifecycle stage [%s] before [%s].', $expectedStage, $beforeStage));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function firstEventValue(array $events, string $stage, string $field): mixed
    {
        foreach ($events as $event) {
            if (($event['stage'] ?? null) === $stage) {
                return $event[$field] ?? null;
            }
        }

        return null;
    }

    /**
     * @return array{events:list<array<string, mixed>>}
     */
    private function completedTrace(): array
    {
        $trace = $this->traceRecorder()->lastCompletedTrace();

        $this->assertNotNull($trace, 'Expected a completed tenant lifecycle trace.');

        return $this->normalizeTrace($trace);
    }

    /**
     * @return array{events:list<array<string, mixed>>}
     */
    private function responseTrace(TestResponse $response): array
    {
        $encoded = $response->headers->get($this->traceRecorder()->responseHeaderName());
        $format = $response->headers->get($this->traceRecorder()->responseHeaderName().'-Format');

        $this->assertIsString($encoded);
        $decoded = base64_decode($encoded, true);
        $this->assertIsString($decoded);

        if ($format === 'base64-gzip-json') {
            $decoded = gzdecode($decoded);
            $this->assertIsString($decoded);
        }

        $trace = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($trace);
        $this->assertArrayHasKey('events', $trace);

        return $this->normalizeTrace($trace);
    }

    /**
     * @param  array{events:list<array<string, mixed>>}  $trace
     * @return array{events:list<array<string, mixed>>}
     */
    private function normalizeTrace(array $trace): array
    {
        $trace['events'] = array_map(function (array $event): array {
            if (array_key_exists('t_ms', $event)) {
                $event['t_ms'] = round((float) $event['t_ms'], 3);
            }

            if (array_key_exists('laravel_start_to_tenancy_ms', $event)) {
                $event['laravel_start_to_tenancy_ms'] = round(
                    (float) $event['laravel_start_to_tenancy_ms'],
                    3,
                );
            }

            return $event;
        }, $trace['events']);

        return $trace;
    }

    private function traceRecorder(): TenantRequestLifecycleTrace
    {
        return $this->app->make(TenantRequestLifecycleTrace::class);
    }

    /**
     * @return array<string, string>
     */
    private function traceHeaders(): array
    {
        return [
            'X-Delphi-Tenant-Lifecycle-Trace' => '1',
            'Accept' => 'application/json',
        ];
    }

    private function assertTenantRuntimeReset(): void
    {
        $tenantConnectionName = $this->tenantConnectionName();
        $contextKey = (string) config('multitenancy.current_tenant_context_key', 'tenantId');
        $containerKey = (string) config('multitenancy.current_tenant_container_key', 'currentTenant');

        $this->assertSame($this->defaultConnectionAtRest, DB::getDefaultConnection());
        $this->assertNull(Tenant::current());
        $this->assertFalse(Context::has($contextKey));
        $this->assertFalse(app()->bound($containerKey));
        $this->assertNull(config("database.connections.{$tenantConnectionName}.database"));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function mongoCollectionCommandsBetween(
        array $events,
        string $afterStage,
        string $beforeStage,
        string $connectionName,
    ): array {
        return array_values(array_filter(
            $this->eventsBetweenStages($events, $afterStage, $beforeStage),
            static fn (array $event): bool => ($event['stage'] ?? null) === "mongo.command.{$connectionName}"
                && is_string($event['collection'] ?? null)
                && trim((string) $event['collection']) !== '',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function eventsBetweenStages(array $events, string $afterStage, string $beforeStage): array
    {
        $startIndex = null;
        $endIndex = null;

        foreach ($events as $index => $event) {
            if (($event['stage'] ?? null) === $afterStage) {
                $startIndex = $index;

                continue;
            }

            if ($startIndex !== null && ($event['stage'] ?? null) === $beforeStage) {
                $endIndex = $index;

                break;
            }
        }

        $this->assertNotNull($startIndex, "Expected stage [{$afterStage}] before collecting bounded events.");
        $this->assertNotNull($endIndex, "Expected stage [{$beforeStage}] after [{$afterStage}] when collecting bounded events.");
        $this->assertGreaterThan($startIndex, $endIndex);

        return array_slice($events, $startIndex + 1, $endIndex - $startIndex - 1);
    }

    private function normalizeTenantRuntimeState(): void
    {
        Tenant::current()?->forgetCurrent();

        Context::forget((string) config('multitenancy.current_tenant_context_key', 'tenantId'));
        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key', 'currentTenant'));

        config([
            sprintf('database.connections.%s.database', $this->tenantConnectionName()) => null,
        ]);

        DB::purge($this->tenantConnectionName());
        DB::setDefaultConnection($this->defaultConnectionAtRest);
    }

    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }

    private function primaryTenant(): Tenant
    {
        return $this->resolveCanonicalTenant(new TenantLabels(
            'lifecycle.tenant.primary',
            'Tenant Iota',
        ));
    }

    private function secondaryTenant(): Tenant
    {
        $primaryTenant = $this->primaryTenant();

        $secondary = Tenant::query()
            ->where('_id', '!=', $primaryTenant->getKey())
            ->orderBy('created_at')
            ->first();

        if ($secondary instanceof Tenant) {
            return $secondary;
        }

        $secondary = Tenant::create([
            'name' => 'Tenant Kappa',
            'subdomain' => 'tenant-kappa',
            'app_domains' => ['tenant-kappa.test'],
        ]);

        $this->normalizeTenantRuntimeState();

        return $secondary;
    }

    private function tenantHost(Tenant $tenant): string
    {
        return sprintf('%s.%s', (string) $tenant->subdomain, $this->host);
    }

    private function environmentUrlForHost(string $host): string
    {
        return $this->apiUrlForHost($host, 'environment');
    }

    private function webUrlForHost(string $host, string $path = '/'): string
    {
        $normalizedPath = trim($path);
        if ($normalizedPath === '') {
            $normalizedPath = '/';
        }
        if (! str_starts_with($normalizedPath, '/')) {
            $normalizedPath = '/'.$normalizedPath;
        }

        return "http://{$host}{$normalizedPath}";
    }

    private function apiUrlForHost(string $host, string $path): string
    {
        $normalizedPath = ltrim($path, '/');

        return "http://{$host}/api/v1/{$normalizedPath}";
    }

    private function issueTenantTraceToken(): string
    {
        $tenant = $this->primaryTenant();
        $tenant->makeCurrent();

        $user = AccountUser::query()->create([
            'name' => 'Trace Token User',
            'emails' => ['trace-token@example.org'],
            'identity_state' => 'registered',
        ]);

        $token = $this->app->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser($user, 'tenant-trace-token', [])
            ->plainTextToken;

        $tenant->forgetCurrent();
        DB::setDefaultConnection($this->defaultConnectionAtRest);

        return $token;
    }

    private function createPublishedFutureGeoAgendaEvent(Tenant $tenant): Event
    {
        $tenant->makeCurrent();

        try {
            $startsAt = Carbon::now()->addDay();
            $event = Event::query()->create([
                'title' => 'Lifecycle Trace Geo Agenda Event',
                'content' => 'Trace fixture',
                'location' => [
                    'mode' => 'physical',
                    'geo' => [
                        'type' => 'Point',
                        'coordinates' => [-40.495395, -20.671339],
                    ],
                ],
                'geo_location' => [
                    'type' => 'Point',
                    'coordinates' => [-40.495395, -20.671339],
                ],
                'type' => [
                    'id' => 'lifecycle-trace',
                    'name' => 'Lifecycle Trace',
                    'slug' => 'lifecycle-trace',
                ],
                'date_time_start' => $startsAt,
                'date_time_end' => $startsAt->copy()->addHours(2),
                'categories' => ['culture'],
                'taxonomy_terms' => [],
                'publication' => [
                    'status' => 'published',
                    'publish_at' => Carbon::now()->subMinute(),
                ],
                'is_active' => true,
            ]);

            $this->app->make(EventOccurrenceSyncService::class)->syncFromEvent($event, [[
                'date_time_start' => $startsAt,
                'date_time_end' => $startsAt->copy()->addHours(2),
            ]], (string) ($event->content ?? ''));

            return $event->fresh();
        } finally {
            $this->normalizeTenantRuntimeState();
        }
    }

    private function seedEnvironmentSnapshot(Tenant $tenant): void
    {
        $tenant->makeCurrent();
        $this->app->make(TenantEnvironmentSnapshotService::class)->repair($tenant, 'trace_seed');
        $this->normalizeTenantRuntimeState();
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $service->initialize(new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Iota', 'subdomain' => 'tenant-iota'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/icon192.png'],
            tenantDomains: ['tenant-iota.test'],
        ));
    }
}
