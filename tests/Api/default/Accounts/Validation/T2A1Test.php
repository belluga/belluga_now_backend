<?php

namespace Tests\Api\default\Accounts\Validation;

use Tests\Api\default\Accounts\Validation\Contracts\ApiDefaultAccountApiValidationTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A1Test extends ApiDefaultAccountApiValidationTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_secondary->account_primary;
        }
    }
}
