<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Identity;

use Tests\Api\v1\Tenants\Identity\Contracts\ApiV1AnonymousIdentityMergerTestContract;
use Tests\Helpers\TenantLabels;

class T2AnonymousIdentityMergerTest extends ApiV1AnonymousIdentityMergerTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_secondary;
        }
    }
}
