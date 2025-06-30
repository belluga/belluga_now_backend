<?php

namespace Tests\Api\default\Accounts\Auth;

use Tests\Api\default\Accounts\Auth\Contracts\ApiDefaultAccountAuthTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A2Test extends ApiDefaultAccountAuthTestContract {
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
