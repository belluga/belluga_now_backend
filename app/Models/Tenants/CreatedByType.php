<?php

declare(strict_types=1);

namespace App\Models\Tenants;

enum CreatedByType: string
{
    case TENANT = 'tenant';
    case ACCOUNT = 'account';
}
