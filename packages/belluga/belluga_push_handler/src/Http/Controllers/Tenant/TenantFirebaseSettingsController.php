<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantFirebaseSettingsRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantFirebaseSettingsController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {}

    public function show(): JsonResponse
    {
        $firebase = $this->pushSettings->currentFirebaseConfig();

        return response()->json(['data' => $firebase]);
    }

    public function update(TenantFirebaseSettingsRequest $request): JsonResponse
    {
        $incoming = $request->validated();
        $firebase = $this->pushSettings->patchFirebaseConfig($request->user(), $incoming);

        return response()->json(['data' => $firebase]);
    }
}
