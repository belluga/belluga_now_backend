<?php

namespace Tests\Api\default\Accounts\Profile;

use Tests\Api\default\Accounts\Profile\Contracts\ApiDefaultAccountUserProfile;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T1A1Test extends ApiDefaultAccountUserProfile {
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
