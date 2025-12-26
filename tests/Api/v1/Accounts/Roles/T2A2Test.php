<?php

namespace Tests\Api\v1\Accounts\Roles;

use Tests\Api\v1\Accounts\Roles\Contracts\ApiV1AccountRolesTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A2Test extends ApiV1AccountRolesTestContract {
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
