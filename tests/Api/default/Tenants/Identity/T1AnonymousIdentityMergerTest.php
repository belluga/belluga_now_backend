<?php

declare(strict_types=1);

namespace Tests\Api\default\Tenants\Identity;

use Tests\Api\default\Tenants\Identity\Contracts\ApiDefaultAnonymousIdentityMergerTestContract;
use Tests\Helpers\TenantLabels;

class T1AnonymousIdentityMergerTest extends ApiDefaultAnonymousIdentityMergerTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }
}
