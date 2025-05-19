<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Models\Tenants\AccountUser;

class AuthControllerTenant extends AuthControllerContract
{
    protected $userModel = AccountUser::class;
}
