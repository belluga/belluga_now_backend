<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Http\Api\v1\Resources\MeResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function tenant(): JsonResponse
    {
        app(TenantRequestLifecycleTrace::class)->record('endpoint.me.controller.enter');

        /** @var AccountUser $user */
        $user = auth()->user();

        $resource = MeResource::fromTenant($user);

        app(TenantRequestLifecycleTrace::class)->record('endpoint.me.response_ready');

        return response()->json($resource);
    }

    public function landlord(): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->user();

        return response()->json(MeResource::fromLandlord($user));
    }
}
