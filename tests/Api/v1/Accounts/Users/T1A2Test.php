<?php

namespace Tests\Api\v1\Accounts\Users;

use Tests\Api\v1\Accounts\Users\Contracts\ApiV1AccountUsersManageTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A2Test extends ApiV1AccountUsersManageTestContract {
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
