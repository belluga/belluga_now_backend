<?php

namespace Tests\Api\v1\Tenants\Account;

use Tests\Api\v1\Tenants\Account\Contracts\ApiV1TenantAccountsTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiV1TenantAccountsTestContract
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }
}
