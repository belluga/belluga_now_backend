<?php

namespace Tests\Api\default\Tenants\Branding;

use Tests\Api\default\Tenants\Branding\Contracts\ApiDefaultBrandingTenantTestContract;
use Tests\Helpers\TenantLabels;

class T1Test extends ApiDefaultBrandingTenantTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }
}
