<?php

namespace Api\default\Tenants\Branding;

use Tests\Api\default\Tenants\Branding\Contracts\ApiDefaultBrandingTenantTestContract;
use Tests\Helpers\TenantLabels;

class T2Test extends ApiDefaultBrandingTenantTestContract {
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }
}
