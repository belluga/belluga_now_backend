<?php

namespace Tests\Api\default\Tenants\Account;

use Tests\Api\default\Tenants\Account\Contracts\ApiDefaultTenantAccountsTestContract;
use Tests\Helpers\TenantLabels;

class T2Test extends ApiDefaultTenantAccountsTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }
}
