<?php

namespace Tests\Api\default\Tenants\Auth;

use Tests\Api\default\Tenants\Auth\Contracts\ApiDefaultPasswordRegistrationTestContract;
use Tests\Helpers\TenantLabels;

class T1PasswordRegistrationTest extends ApiDefaultPasswordRegistrationTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }
}
