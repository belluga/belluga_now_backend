<?php

declare(strict_types=1);

namespace Tests\Feature\Landlord;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Integration\Push\PushTenantContextAdapter;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class TenantDefaultConnectionRestorationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

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

        $this->assertSame($this->expectedDefaultConnection(), DB::getDefaultConnection());
        $this->assertNull(Tenant::current());
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
        return (string) env('DB_CONNECTION', 'mongodb');
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
