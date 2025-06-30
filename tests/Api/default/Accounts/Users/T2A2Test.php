<?php

namespace Tests\Api\default\Accounts\Users;

use Tests\Api\default\Accounts\Users\Contracts\ApiDefaultAccountUsersManageTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A2Test extends ApiDefaultAccountUsersManageTestContract {
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
