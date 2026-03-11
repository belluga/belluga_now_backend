<?php

namespace Tests\Api\v1\Accounts\Validation;

use Tests\Api\v1\Accounts\Validation\Contracts\ApiV1AccountApiValidationTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A2Test extends ApiV1AccountApiValidationTestContract
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_secondary->account_secondary;
        }
    }
}
