<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Resources\MeResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function tenant(): JsonResponse
    {
        /** @var AccountUser $user */
        $user = auth()->user();

        return response()->json(MeResource::fromTenant($user));
    }

    public function landlord(): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->user();

        return response()->json(MeResource::fromLandlord($user));
    }
}
