<?php

namespace Tests\Api\v1\Tenants\Users;

use Tests\Api\v1\Tenants\Users\Contracts\ApiV1TenantApiTenantUsersTestContract;
use Tests\Helpers\TenantLabels;

class T2Test extends ApiV1TenantApiTenantUsersTestContract
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }
}
