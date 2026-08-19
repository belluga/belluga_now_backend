<?php

namespace App\Http\Middleware;

use App\Actions\DomainTenantFinder;
use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Models\Landlord\Tenant;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancy
{
    public function __construct(
        private readonly DomainTenantFinder $tenantFinder,
        private readonly TenantRequestLifecycleTrace $lifecycleTrace,
    ) {}

    public function handle($request, Closure $next)
    {
        $bootstrapState = $this->resetTenantRuntimeState();
        $this->lifecycleTrace->beginRequest($request);
        $this->recordBootstrapReset($bootstrapState);
        $response = null;

        try {
            $tenant = $this->tenantFinder->findForRequest($request);

            if ($tenant !== null) {
                $this->lifecycleTrace->record('tenant.matched', [
                    'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
                ]);
                $tenant->makeCurrent();
            } else {
                $this->lifecycleTrace->record('tenant.not_found');
            }

            $response = $next($request);

            $this->lifecycleTrace->record('request.response_handoff', [
                'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
            ]);

            return $response;
        } finally {
            $this->lifecycleTrace->record('request.cleanup.start');
            $this->resetTenantRuntimeState(force: true);
            $this->lifecycleTrace->record('request.cleanup.complete');

            if ($response instanceof Response) {
                $this->lifecycleTrace->appendResponseHeader($response);
            }

            $this->lifecycleTrace->finishRequest();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resetTenantRuntimeState(bool $force = false): array
    {
        $snapshot = $this->lifecycleTrace->snapshotRuntime();
        $contextKey = (string) config('multitenancy.current_tenant_context_key', 'tenantId');
        $snapshot['tenant_context_present'] = Context::has($contextKey);

        if (! $force && ! $this->runtimeIsDirty($snapshot)) {
            return $snapshot;
        }

        Tenant::forgetCurrent();

        Context::forget($contextKey);
        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key', 'currentTenant'));

        config([
            sprintf('database.connections.%s.database', $this->tenantConnectionName()) => null,
        ]);

        DB::purge($this->tenantConnectionName());
        DB::setDefaultConnection($this->baselineDefaultConnection());

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $bootstrapState
     */
    private function recordBootstrapReset(array $bootstrapState): void
    {
        if (! $this->runtimeIsDirty($bootstrapState)) {
            return;
        }

        $this->lifecycleTrace->record('request.bootstrap_reset', [
            'bootstrap_default_connection' => $bootstrapState['default_connection'] ?? null,
            'bootstrap_tenant_current' => $bootstrapState['tenant_current'] ?? null,
            'bootstrap_tenant_context_present' => $bootstrapState['tenant_context_present'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function runtimeIsDirty(array $snapshot): bool
    {
        return ($snapshot['default_connection'] ?? null) !== $this->baselineDefaultConnection()
            || ($snapshot['tenant_current'] ?? null) !== null
            || (($snapshot['tenant_context_present'] ?? false) === true);
    }

    private function tenantConnectionName(): string
    {
        return (string) config('multitenancy.tenant_database_connection_name', 'tenant');
    }

    private function baselineDefaultConnection(): string
    {
        return (string) config('database.default', 'mongodb');
    }
}
