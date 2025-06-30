<?php

namespace Tests\Api\default\Tenants\Middleware;

use Tests\Api\default\Tenants\Middleware\Contracts\ApiDefaultTenantsMiddlewareTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiDefaultTenantsMiddlewareTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected TenantLabels $tenant_cross {
        get {
            return $this->landlord->tenant_secondary;
        }
    }
}
