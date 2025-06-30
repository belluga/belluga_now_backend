<?php

namespace Tests\Api\default\Tenants\Account;

use Tests\Api\default\Tenants\Account\Contracts\ApiDefaultTenantAccountsTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiDefaultTenantAccountsTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }
}
