<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Models\Landlord\LandlordUser;

class AuthControllerLandlord extends AuthControllerContract
{

    protected $userModel = LandlordUser::class;
}
