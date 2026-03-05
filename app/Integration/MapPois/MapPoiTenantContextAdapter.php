<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Models\Landlord\Tenant;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;

class MapPoiTenantContextAdapter implements MapPoiTenantContextContract
{
    public function currentTenantId(): ?string
    {
        $tenant = Tenant::current();

        return $tenant ? (string) $tenant->_id : null;
    }
}
