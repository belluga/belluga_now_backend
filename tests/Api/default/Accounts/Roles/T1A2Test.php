<?php

namespace Tests\Api\default\Accounts\Roles;

use Tests\Api\default\Accounts\Roles\Contracts\ApiDefaultAccountRolesTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A2Test extends ApiDefaultAccountRolesTestContract {
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
