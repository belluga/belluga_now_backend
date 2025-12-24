<?php

namespace Tests\Api\v1\Accounts\Roles;

use Tests\Api\v1\Accounts\Roles\Contracts\ApiV1AccountRolesTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A2Test extends ApiV1AccountRolesTestContract {
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
