<?php

namespace Tests\Api\default\Accounts\Profile;

use Tests\Api\default\Accounts\Profile\Contracts\ApiDefaultAccountUserProfile;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;

class T2A2Test extends ApiDefaultAccountUserProfile {
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
