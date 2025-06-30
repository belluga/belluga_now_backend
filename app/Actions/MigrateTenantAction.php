<?php

declare(strict_types=1);

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
