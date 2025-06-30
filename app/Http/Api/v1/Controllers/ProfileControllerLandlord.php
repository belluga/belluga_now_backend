<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Models\Landlord\LandlordUser;

class ProfileControllerLandlord extends ProfileControllerContract
{
    protected $userModel = LandlordUser::class;

}
