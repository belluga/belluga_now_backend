<?php

namespace App\Tasks;

use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchMongoTenantDatabaseTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        if (is_null($tenant->getDatabaseName())) {
            return;
        }

        $connectionName = config('multitenancy.tenant_database_connection_name');

        if (! $connectionName) {
            return;
        }

        // Atualiza a configuração com o banco de dados do tenant atual
        config([
            "database.connections.{$connectionName}" => array_merge(
                config("database.connections.{$connectionName}"),
                ['database' => $tenant->getDatabaseName()]
            )
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
    }

    public function forgetCurrent(): void
    {
        $connectionName = config('multitenancy.tenant_database_connection_name');

        if (! $connectionName) {
            return;
        }

        config([
            "database.connections.{$connectionName}.database" => null
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection(config('database.default'));
    }
}
