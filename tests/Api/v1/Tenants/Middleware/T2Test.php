<?php

namespace Tests\Api\v1\Tenants\Middleware;

use Tests\Api\v1\Tenants\Middleware\Contracts\ApiV1TenantsMiddlewareTestContract;
use Tests\Helpers\TenantLabels;

class T2Test extends ApiV1TenantsMiddlewareTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }

    protected TenantLabels $tenant_cross {
        get {
            return $this->landlord->tenant_primary;
        }
    }
}
