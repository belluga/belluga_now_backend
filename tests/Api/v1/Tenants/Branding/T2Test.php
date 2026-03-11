<?php

namespace Tests\Api\v1\Tenants\Branding;

use Tests\Api\v1\Tenants\Branding\Contracts\ApiV1BrandingTenantTestContract;
use Tests\Helpers\TenantLabels;

class T2Test extends ApiV1BrandingTenantTestContract
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_secondary;
        }
    }
}
