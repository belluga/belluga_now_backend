<?php

namespace Tests\Api\v1\Accounts\Profile;

use Tests\Api\v1\Accounts\Profile\Contracts\ApiV1AccountUserProfile;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A1Test extends ApiV1AccountUserProfile {
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
