<?php

namespace Tests\Api\v1\Tenants\Auth;

use Tests\Api\v1\Tenants\Auth\Contracts\ApiV1AnonymousIdentityTestContract;
use Tests\Helpers\TenantLabels;

class T1AnonymousTest extends ApiV1AnonymousIdentityTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }
}
