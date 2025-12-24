<?php

namespace Tests\Api\v1\Accounts\Auth;

use Tests\Api\v1\Accounts\Auth\Contracts\ApiV1AccountAuthTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A2Test extends ApiV1AccountAuthTestContract {
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
