<?php

namespace Tests\Api\v1\Accounts\Validation;

use Tests\Api\v1\Accounts\Validation\Contracts\ApiV1AccountApiValidationTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A1Test extends ApiV1AccountApiValidationTestContract
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_primary->account_primary;
        }
    }
}
