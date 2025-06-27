<?php

namespace Tests\Api\default\Accounts\Middleware;

use Tests\Api\default\Accounts\Middleware\Contracts\ApiDefaultAccountsMiddlewareTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A2Test extends ApiDefaultAccountsMiddlewareTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected TenantLabels $tenant_cross {
        get{
            return $this->landlord->tenant_secondary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_primary->account_secondary;
        }
    }

    protected AccountLabels $account_cross {
        get {
            return $this->landlord->tenant_primary->account_primary;
        }
    }
}
