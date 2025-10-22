<?php

namespace Tests\Api\default\Tenants\Auth;

use Tests\Api\default\Tenants\Auth\Contracts\ApiDefaultAnonymousIdentityTestContract;
use Tests\Helpers\TenantLabels;

class T2AnonymousTest extends ApiDefaultAnonymousIdentityTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_secondary;
        }
    }
}
