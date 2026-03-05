<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Landlord\Tenant;
use Belluga\Events\Contracts\EventTenantContextContract;

class TenantContextAdapter implements EventTenantContextContract
{
    public function resolveCurrentTenantId(): ?string
    {
        $tenant = Tenant::resolve();

        return $tenant ? (string) $tenant->_id : null;
    }
}
