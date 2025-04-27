<?php

namespace App\Actions;

use Spatie\Multitenancy\Actions\MigrateTenantAction as BaseMigrateTenantAction;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use App\Tasks\SwitchMongoTenantDatabaseTask;

class MigrateTenantAction extends BaseMigrateTenantAction
{
    protected function getSwitchTenantTask(): SwitchTenantTask
    {
        return new SwitchMongoTenantDatabaseTask();
    }
}
