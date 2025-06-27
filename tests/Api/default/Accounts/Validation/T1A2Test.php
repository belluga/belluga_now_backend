<?php

namespace Tests\Api\default\Accounts\Validation;

use Tests\Api\default\Accounts\Validation\Contracts\ApiDefaultAccountApiValidationTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A2Test extends ApiDefaultAccountApiValidationTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_primary->account_secondary;
        }
    }
}
