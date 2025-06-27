<?php

namespace Tests\Api\default\Accounts\Roles;

use Tests\Api\default\Accounts\Roles\Contracts\ApiDefaultAccountRolesTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A1Test extends ApiDefaultAccountRolesTestContract {
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
