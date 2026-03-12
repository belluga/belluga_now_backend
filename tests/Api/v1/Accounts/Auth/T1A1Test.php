<?php

namespace Tests\Api\v1\Accounts\Auth;

use Tests\Api\v1\Accounts\Auth\Contracts\ApiV1AccountAuthTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A1Test extends ApiV1AccountAuthTestContract
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
