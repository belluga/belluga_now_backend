<?php

declare(strict_types=1);

namespace App\Integration\Email;

use App\Models\Landlord\Tenant;
use Belluga\Email\Contracts\EmailTenantContextContract;

class TenantCurrentContextAdapter implements EmailTenantContextContract
{
    public function currentTenantDisplayName(): ?string
    {
        $name = Tenant::current()?->name;
        if (! is_string($name)) {
            return null;
        }

        $normalized = trim($name);

        return $normalized === '' ? null : $normalized;
    }
}
