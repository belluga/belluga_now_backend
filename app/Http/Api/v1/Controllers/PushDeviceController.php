<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\PushRegisterRequest;
use App\Http\Api\v1\Requests\PushUnregisterRequest;
use App\Http\Api\v1\Services\PushDeviceService;
use App\Models\Landlord\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PushDeviceController extends Controller
{
    public function __construct(
        private readonly PushDeviceService $service
    ) {
    }

    public function register(PushRegisterRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        $this->service->register($user, $payload);

        $tenant = Tenant::current();

        return response()->json([
            'tenant_id' => $tenant ? (string) $tenant->_id : null,
            'ok' => true,
        ]);
    }

    public function unregister(PushUnregisterRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        $this->service->unregister($user, $payload);

        $tenant = Tenant::current();

        return response()->json([
            'tenant_id' => $tenant ? (string) $tenant->_id : null,
            'ok' => true,
        ]);
    }
}
