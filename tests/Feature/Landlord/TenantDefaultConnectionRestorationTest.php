<?php

declare(strict_types=1);

namespace Tests\Feature\Landlord;

use App\Actions\MigrateTenantAction as TenantMigrationAction;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Integration\Events\TenantExecutionContextAdapter;
use App\Integration\Push\PushTenantContextAdapter;
use App\Integration\Settings\TenantScopeContextAdapter;
use App\Models\Landlord\Tenant;
use App\Tasks\SwitchMongoTenantDatabaseTask;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class TenantDefaultConnectionRestorationTest extends TestCase
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

    public function test_single_tenant_cycle_restores_original_default_connection(): void
    {
        $tenant = $this->primaryTenant();

        $tenant->makeCurrent();

        $this->assertTenantRuntimeBoundTo($tenant);

        $tenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_switching_between_tenants_retargets_database_and_restores_landlord_default_after_forget(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();
        $this->assertTenantRuntimeBoundTo($primaryTenant);

        $secondaryTenant->makeCurrent();
        $this->assertTenantRuntimeBoundTo($secondaryTenant);

        $secondaryTenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_tenant_cycle_restores_the_exact_preexisting_default_connection(): void
    {
        $tenant = $this->primaryTenant();

        DB::setDefaultConnection('landlord');

        $tenant->makeCurrent();

        $this->assertTenantRuntimeBoundTo($tenant);

        $tenant->forgetCurrent();

        $this->assertSame('landlord', DB::getDefaultConnection());
        $this->assertNull(Tenant::current());
        $this->assertNull(config(sprintf('database.connections.%s.database', $this->tenantConnectionName())));
    }

    public function test_migrate_tenant_action_resolves_the_switch_task_from_the_container(): void
    {
        $action = new class extends TenantMigrationAction
        {
            public function exposedSwitchTask(): SwitchTenantTask
            {
                return $this->getSwitchTenantTask();
            }
        };

        $this->assertInstanceOf(SwitchMongoTenantDatabaseTask::class, $action->exposedSwitchTask());
    }

    public function test_exceptional_callback_cleanup_restores_previous_tenant_runtime(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();

        try {
            $this->app->make(PushTenantContextAdapter::class)
                ->runForTenantSlug($secondaryTenant->slug, static function (): void {
                    throw new \RuntimeException('forced failure');
                });

            $this->fail('The tenant callback must rethrow the forced failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced failure', $exception->getMessage());
        }

        $this->assertTenantRuntimeBoundTo($primaryTenant);

        $primaryTenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_push_tenant_context_adapter_restores_exact_previous_default_connection(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $result = $this->app->make(PushTenantContextAdapter::class)
            ->runForTenantSlug($secondaryTenant->slug, static fn (): string => 'push-success');

        $this->assertSame('push-success', $result);
        $this->assertTenantContextWithExpectedDefaultConnection($primaryTenant, 'landlord');

        $this->normalizeTenantRuntimeState();
        $this->assertTenantRuntimeReset();
    }

    public function test_push_tenant_context_adapter_rebinds_same_tenant_runtime_before_callback(): void
    {
        $primaryTenant = $this->primaryTenant();

        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $result = $this->app->make(PushTenantContextAdapter::class)
            ->runForTenantSlug($primaryTenant->slug, function () use ($primaryTenant): string {
                $this->assertTenantRuntimeBoundTo($primaryTenant);

                return 'push-same-tenant-success';
            });

        $this->assertSame('push-same-tenant-success', $result);
        $this->assertTenantContextWithExpectedDefaultConnection($primaryTenant, 'landlord');

        $this->normalizeTenantRuntimeState();
        $this->assertTenantRuntimeReset();
    }

    public function test_settings_tenant_scope_adapter_restores_previous_tenant_runtime_after_success(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();

        $result = $this->app->make(TenantScopeContextAdapter::class)
            ->runForTenantSlug($secondaryTenant->slug, function () use ($secondaryTenant): string {
                $this->assertTenantRuntimeBoundTo($secondaryTenant);

                return 'scoped-success';
            });

        $this->assertSame('scoped-success', $result);
        $this->assertTenantRuntimeBoundTo($primaryTenant);

        $primaryTenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_settings_tenant_scope_adapter_restores_previous_tenant_runtime_after_exception(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();

        try {
            $this->app->make(TenantScopeContextAdapter::class)
                ->runForTenantSlug($secondaryTenant->slug, function () use ($secondaryTenant): void {
                    $this->assertTenantRuntimeBoundTo($secondaryTenant);
                    throw new \RuntimeException('forced settings failure');
                });

            $this->fail('The tenant scope callback must rethrow the forced failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced settings failure', $exception->getMessage());
        }

        $this->assertTenantRuntimeBoundTo($primaryTenant);

        $primaryTenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_settings_tenant_scope_adapter_restores_exact_previous_default_connection(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $result = $this->app->make(TenantScopeContextAdapter::class)
            ->runForTenantSlug($secondaryTenant->slug, static fn (): string => 'settings-success');

        $this->assertSame('settings-success', $result);
        $this->assertTenantContextWithExpectedDefaultConnection($primaryTenant, 'landlord');

        $this->normalizeTenantRuntimeState();
        $this->assertTenantRuntimeReset();
    }

    public function test_settings_tenant_scope_adapter_rebinds_same_tenant_runtime_before_callback(): void
    {
        $primaryTenant = $this->primaryTenant();

        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $result = $this->app->make(TenantScopeContextAdapter::class)
            ->runForTenantSlug($primaryTenant->slug, function () use ($primaryTenant): string {
                $this->assertTenantRuntimeBoundTo($primaryTenant);

                return 'settings-same-tenant-success';
            });

        $this->assertSame('settings-same-tenant-success', $result);
        $this->assertTenantContextWithExpectedDefaultConnection($primaryTenant, 'landlord');

        $this->normalizeTenantRuntimeState();
        $this->assertTenantRuntimeReset();
    }

    public function test_settings_tenant_scope_adapter_resets_runtime_when_no_previous_tenant_exists(): void
    {
        $secondaryTenant = $this->secondaryTenant();

        $result = $this->app->make(TenantScopeContextAdapter::class)
            ->runForTenantSlug($secondaryTenant->slug, function () use ($secondaryTenant): string {
                $this->assertTenantRuntimeBoundTo($secondaryTenant);

                return 'no-previous-tenant';
            });

        $this->assertSame('no-previous-tenant', $result);
        $this->assertTenantRuntimeReset();
    }

    public function test_settings_tenant_scope_adapter_resets_runtime_after_exception_when_no_previous_tenant_exists(): void
    {
        $secondaryTenant = $this->secondaryTenant();

        try {
            $this->app->make(TenantScopeContextAdapter::class)
                ->runForTenantSlug($secondaryTenant->slug, function () use ($secondaryTenant): void {
                    $this->assertTenantRuntimeBoundTo($secondaryTenant);
                    throw new \RuntimeException('forced settings failure without previous tenant');
                });

            $this->fail('The tenant scope callback must rethrow the forced failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced settings failure without previous tenant', $exception->getMessage());
        }

        $this->assertTenantRuntimeReset();
    }

    public function test_event_tenant_execution_adapter_restores_previous_tenant_runtime_after_iteration(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();

        $visitedTenantIds = [];
        $this->app->make(TenantExecutionContextAdapter::class)
            ->runForEachTenant(function () use (&$visitedTenantIds): void {
                $currentTenant = Tenant::current();
                $this->assertInstanceOf(Tenant::class, $currentTenant);
                $this->assertTenantRuntimeBoundTo($currentTenant);
                $visitedTenantIds[] = (string) $currentTenant->getKey();
            });

        $this->assertEqualsCanonicalizing(
            [
                (string) $primaryTenant->getKey(),
                (string) $secondaryTenant->getKey(),
            ],
            $visitedTenantIds,
        );
        $this->assertTenantRuntimeBoundTo($primaryTenant);

        $primaryTenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_event_tenant_execution_adapter_restores_previous_tenant_runtime_after_exception(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();

        $visitedTenantIds = [];

        try {
            $this->app->make(TenantExecutionContextAdapter::class)
                ->runForEachTenant(function () use (&$visitedTenantIds, $secondaryTenant): void {
                    $currentTenant = Tenant::current();
                    $this->assertInstanceOf(Tenant::class, $currentTenant);
                    $this->assertTenantRuntimeBoundTo($currentTenant);
                    $visitedTenantIds[] = (string) $currentTenant->getKey();

                    if ((string) $currentTenant->getKey() === (string) $secondaryTenant->getKey()) {
                        throw new \RuntimeException('forced events failure');
                    }
                });

            $this->fail('The tenant iteration callback must rethrow the forced failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced events failure', $exception->getMessage());
        }

        $this->assertContains((string) $secondaryTenant->getKey(), $visitedTenantIds);
        $this->assertTenantRuntimeBoundTo($primaryTenant);

        $primaryTenant->forgetCurrent();

        $this->assertTenantRuntimeReset();
    }

    public function test_event_tenant_execution_adapter_restores_exact_previous_default_connection(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryTenant->makeCurrent();
        DB::setDefaultConnection('landlord');

        $visitedTenantIds = [];
        $this->app->make(TenantExecutionContextAdapter::class)
            ->runForEachTenant(function () use (&$visitedTenantIds): void {
                $currentTenant = Tenant::current();
                $this->assertInstanceOf(Tenant::class, $currentTenant);
                $this->assertTenantRuntimeBoundTo($currentTenant);
                $visitedTenantIds[] = (string) $currentTenant->getKey();
            });

        $this->assertEqualsCanonicalizing(
            [
                (string) $primaryTenant->getKey(),
                (string) $secondaryTenant->getKey(),
            ],
            $visitedTenantIds,
        );
        $this->assertTenantContextWithExpectedDefaultConnection($primaryTenant, 'landlord');

        $this->normalizeTenantRuntimeState();
        $this->assertTenantRuntimeReset();
    }

    public function test_event_tenant_execution_adapter_resets_runtime_after_iteration_when_no_previous_tenant_exists(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $visitedTenantIds = [];
        $this->app->make(TenantExecutionContextAdapter::class)
            ->runForEachTenant(function () use (&$visitedTenantIds): void {
                $currentTenant = Tenant::current();
                $this->assertInstanceOf(Tenant::class, $currentTenant);
                $this->assertTenantRuntimeBoundTo($currentTenant);
                $visitedTenantIds[] = (string) $currentTenant->getKey();
            });

        $this->assertEqualsCanonicalizing(
            [
                (string) $primaryTenant->getKey(),
                (string) $secondaryTenant->getKey(),
            ],
            $visitedTenantIds,
        );
        $this->assertTenantRuntimeReset();
    }

    public function test_event_tenant_execution_adapter_resets_runtime_after_exception_when_no_previous_tenant_exists(): void
    {
        $secondaryTenant = $this->secondaryTenant();
        $visitedTenantIds = [];

        try {
            $this->app->make(TenantExecutionContextAdapter::class)
                ->runForEachTenant(function () use (&$visitedTenantIds, $secondaryTenant): void {
                    $currentTenant = Tenant::current();
                    $this->assertInstanceOf(Tenant::class, $currentTenant);
                    $this->assertTenantRuntimeBoundTo($currentTenant);
                    $visitedTenantIds[] = (string) $currentTenant->getKey();

                    if ((string) $currentTenant->getKey() === (string) $secondaryTenant->getKey()) {
                        throw new \RuntimeException('forced events failure without previous tenant');
                    }
                });

            $this->fail('The tenant iteration callback must rethrow the forced failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced events failure without previous tenant', $exception->getMessage());
        }

        $this->assertContains((string) $secondaryTenant->getKey(), $visitedTenantIds);
        $this->assertTenantRuntimeReset();
    }

    public function test_same_process_success_failure_and_retry_cycles_do_not_leak_default_connection_state(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $this->runTenantWorkUnit($primaryTenant);
        $this->assertTenantRuntimeReset();

        try {
            $this->runTenantWorkUnit($secondaryTenant, fail: true);
            $this->fail('The failing work unit must rethrow its forced failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced failure', $exception->getMessage());
        }

        $this->assertTenantRuntimeReset();

        $this->runTenantWorkUnit($primaryTenant);

        $this->assertTenantRuntimeReset();
    }

    private function runTenantWorkUnit(Tenant $tenant, bool $fail = false): void
    {
        $tenant->makeCurrent();

        try {
            $this->assertTenantRuntimeBoundTo($tenant);

            if ($fail) {
                throw new \RuntimeException('forced failure');
            }
        } finally {
            $tenant->forgetCurrent();
        }
    }

    private function assertTenantRuntimeBoundTo(Tenant $tenant): void
    {
        $tenantConnectionName = $this->tenantConnectionName();

        $this->assertSame($tenantConnectionName, DB::getDefaultConnection());
        $this->assertSame((string) $tenant->getKey(), (string) Tenant::current()?->getKey());
        $this->assertSame(
            $tenant->database,
            (string) DB::connection($tenantConnectionName)->getDatabase()->getDatabaseName()
        );
        $this->assertSame(
            $tenant->database,
            (string) config("database.connections.{$tenantConnectionName}.database")
        );
    }

    private function assertTenantRuntimeReset(): void
    {
        $tenantConnectionName = $this->tenantConnectionName();
        $contextKey = (string) config('multitenancy.current_tenant_context_key', 'tenantId');
        $containerKey = (string) config('multitenancy.current_tenant_container_key', 'currentTenant');

        $this->assertSame($this->expectedDefaultConnection(), DB::getDefaultConnection());
        $this->assertNull(Tenant::current());
        $this->assertFalse(Context::has($contextKey));
        $this->assertFalse(app()->bound($containerKey));
        $this->assertNull(config("database.connections.{$tenantConnectionName}.database"));
    }

    private function assertTenantContextWithExpectedDefaultConnection(
        Tenant $tenant,
        string $expectedDefaultConnection,
    ): void {
        $tenantConnectionName = $this->tenantConnectionName();

        $this->assertSame($expectedDefaultConnection, DB::getDefaultConnection());
        $this->assertSame((string) $tenant->getKey(), (string) Tenant::current()?->getKey());
        $this->assertSame(
            $tenant->database,
            (string) config("database.connections.{$tenantConnectionName}.database")
        );
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
        DB::setDefaultConnection($this->expectedDefaultConnection());
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

    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }

    private function expectedDefaultConnection(): string
    {
        return $this->defaultConnectionAtRest;
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
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
            tenantDomains: ['tenant-iota.test']
        );

        $service->initialize($payload);
    }
}
