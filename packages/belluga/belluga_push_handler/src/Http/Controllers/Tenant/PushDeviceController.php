<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Belluga\PushHandler\Http\Requests\PushRegisterRequest;
use Belluga\PushHandler\Http\Requests\PushUnregisterRequest;
use Belluga\PushHandler\Services\PushDeviceService;
use Illuminate\Http\JsonResponse;

class PushDeviceController
{
    public function __construct(
        private readonly PushDeviceService $service,
        private readonly PushTenantContextContract $tenantContext
    ) {}

    public function register(PushRegisterRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        $this->service->register($user, $payload);

        return response()->json([
            'tenant_id' => $this->tenantContext->currentTenantId(),
            'ok' => true,
        ]);
    }

    public function unregister(PushUnregisterRequest $request): JsonResponse
    {
        $user = $request->user();
        $payload = $request->validated();

        $this->service->unregister($user, $payload);

        return response()->json([
            'tenant_id' => $this->tenantContext->currentTenantId(),
            'ok' => true,
        ]);
    }
}
