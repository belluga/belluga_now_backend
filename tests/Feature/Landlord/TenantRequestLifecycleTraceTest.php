<?php

declare(strict_types=1);

namespace Tests\Feature\Landlord;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Environment\EnvironmentResolverService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
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
            "mongo.first.{$tenantConnection}",
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

        $this->assertSame(
            $this->traceRecorder()->tenantFingerprint($tenant),
            $this->firstEventValue($trace['events'], 'tenant.matched', 'tenant_target')
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
        return Tenant::query()
            ->orderBy('created_at')
            ->firstOrFail();
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
