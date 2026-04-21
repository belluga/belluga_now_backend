<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Environment;

use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Jobs\Environment\RebuildTenantEnvironmentSnapshotJob;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantEnvironmentSnapshotDispatchGuardTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    public function test_dispatch_refresh_for_current_tenant_requires_consistent_context(): void
    {
        $tenant = Tenant::create([
            'name' => 'Context Drift Tenant',
            'subdomain' => 'context-drift-tenant',
        ]);

        $tenant->makeCurrent();
        Context::forget((string) config('multitenancy.current_tenant_context_key', 'tenantId'));

        Queue::fake();

        $this->app->make(TenantEnvironmentSnapshotService::class)
            ->dispatchRefreshForCurrentTenant('context_drift_regression');

        Queue::assertNothingPushed();
    }

    public function test_dispatch_refresh_for_explicit_tenant_noops_without_landlord_context(): void
    {
        $tenant = Tenant::create([
            'name' => 'Landlordless Tenant',
            'subdomain' => 'landlordless-tenant',
        ]);

        Queue::fake();

        $this->app->make(TenantEnvironmentSnapshotService::class)
            ->dispatchRefreshForTenant($tenant, 'missing_landlord_regression');

        Queue::assertNothingPushed();
    }
}
