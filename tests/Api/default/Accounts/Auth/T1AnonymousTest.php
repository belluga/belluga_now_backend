<?php

namespace Tests\Api\default\Accounts\Auth;

use Tests\Api\default\Accounts\Auth\Contracts\ApiDefaultAnonymousIdentityTestContract;
use Tests\Helpers\TenantLabels;

class T1AnonymousTest extends ApiDefaultAnonymousIdentityTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }
}

