<?php

namespace Tests\Api\default\Tenants\Users;

use Tests\Api\default\Tenants\Users\Contracts\ApiDefaultTenantApiTenantUsersTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiDefaultTenantApiTenantUsersTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }
}
