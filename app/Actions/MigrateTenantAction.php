<?php

declare(strict_types=1);

namespace App\Actions;

use App\Tasks\SwitchMongoTenantDatabaseTask;
use Spatie\Multitenancy\Actions\MigrateTenantAction as BaseMigrateTenantAction;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class MigrateTenantAction extends BaseMigrateTenantAction
{
    protected function getSwitchTenantTask(): SwitchTenantTask
    {
        return new SwitchMongoTenantDatabaseTask;
    }
}
