<?php

namespace Tests\Api\v1\Accounts\Middleware;

use Tests\Api\v1\Accounts\Middleware\Contracts\ApiV1AccountsMiddlewareTestContract;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A2Test extends ApiV1AccountsMiddlewareTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }

    protected TenantLabels $tenant_cross {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_secondary->account_secondary;
        }
    }

    protected AccountLabels $account_cross {
        get {
            return $this->landlord->tenant_secondary->account_primary;
        }
    }
}
