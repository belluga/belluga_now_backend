<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchMongoTenantDatabaseTask implements SwitchTenantTask
{
    private ?string $originalDefaultConnection = null;

    public function __construct(private readonly TenantRequestLifecycleTrace $lifecycleTrace) {}

    public function makeCurrent(IsTenant $tenant): void
    {
        $tenantDatabase = $tenant->getDatabaseName();

        $this->lifecycleTrace->record('tenant.switch.start', [
            'tenant_target' => $this->lifecycleTrace->tenantFingerprint($tenant),
            'database_target' => $this->lifecycleTrace->redactIdentifier($tenantDatabase),
        ]);

        if ($tenantDatabase === null) {
            $this->lifecycleTrace->record('tenant.switch.skipped_missing_database');

            return;
        }

        $connectionName = config('multitenancy.tenant_database_connection_name');

        if (! $connectionName) {
            $this->lifecycleTrace->record('tenant.switch.skipped_missing_connection');

            return;
        }

        if ($this->originalDefaultConnection === null) {
            $this->originalDefaultConnection = DB::getDefaultConnection();
            $this->lifecycleTrace->record('tenant.switch.original_default_captured', [
                'original_default' => $this->originalDefaultConnection,
            ]);
        }

        config([
            "database.connections.$connectionName" => array_merge(
                config("database.connections.$connectionName"),
                ['database' => $tenantDatabase]
            ),
        ]);

        $this->lifecycleTrace->record('tenant.switch.connection_configured', [
            'connection' => $connectionName,
        ]);

        $this->lifecycleTrace->disarmConnectionTrace((string) $connectionName);
        DB::purge($connectionName);
        $this->lifecycleTrace->record('tenant.switch.connection_purged', [
            'connection' => $connectionName,
        ]);

        DB::setDefaultConnection($connectionName);
        $this->lifecycleTrace->record('tenant.switch.default_connection_selected', [
            'connection' => $connectionName,
        ]);

        $this->lifecycleTrace->armConnectionTrace((string) $connectionName);
        $this->lifecycleTrace->record('tenant.switch.complete', [
            'connection' => $connectionName,
        ]);
    }

    public function forgetCurrent(): void
    {
        $connectionName = config('multitenancy.tenant_database_connection_name');

        if (! $connectionName) {
            $this->lifecycleTrace->record('tenant.forget.skipped_missing_connection');

            return;
        }

        $this->lifecycleTrace->record('tenant.forget.start', [
            'connection' => $connectionName,
        ]);

        config([
            "database.connections.$connectionName.database" => null,
        ]);

        $originalDefaultConnection = $this->originalDefaultConnection;
        $this->originalDefaultConnection = null;

        $this->lifecycleTrace->disarmConnectionTrace((string) $connectionName);
        DB::purge($connectionName);
        $this->lifecycleTrace->record('tenant.forget.connection_purged', [
            'connection' => $connectionName,
        ]);

        if ($originalDefaultConnection !== null) {
            DB::setDefaultConnection($originalDefaultConnection);
            $this->lifecycleTrace->record('tenant.forget.default_restored', [
                'restored_default' => $originalDefaultConnection,
            ]);
        }

        $this->lifecycleTrace->record('tenant.forget.complete', [
            'connection' => $connectionName,
        ]);
    }
}
