<?php

namespace Tests\Api\v1\Tenants\Roles;

use Tests\Api\v1\Tenants\Roles\Contracts\ApiV1TenantRolesTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiV1TenantRolesTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }
}
