<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Models\Tenants\TenantUser;

class AuthControllerTenant extends AuthControllerContract
{
    protected $userModel = TenantUser::class;
}
