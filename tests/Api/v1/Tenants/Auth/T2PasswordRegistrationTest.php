<?php

namespace Tests\Api\v1\Tenants\Auth;

use Tests\Api\v1\Tenants\Auth\Contracts\ApiV1PasswordRegistrationTestContract;
use Tests\Helpers\TenantLabels;

class T2PasswordRegistrationTest extends ApiV1PasswordRegistrationTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_secondary;
        }
    }
}
