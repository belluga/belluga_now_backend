<?php

namespace Tests\Api\default\Tenants\Roles;

use Tests\Api\default\Tenants\Roles\Contracts\ApiDefaultTenantRolesTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiDefaultTenantRolesTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }
}
