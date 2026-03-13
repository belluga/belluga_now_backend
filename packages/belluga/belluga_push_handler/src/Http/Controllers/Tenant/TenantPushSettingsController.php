<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantPushSettingsRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantPushSettingsController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {}

    public function show(): JsonResponse
    {
        $push = $this->pushSettings->currentPushConfig();

        return response()->json([
            'data' => $this->pushSettings->extractPushSettingsForResponse($push),
        ]);
    }

    public function update(TenantPushSettingsRequest $request): JsonResponse
    {
        $push = $this->pushSettings->patchPushConfig($request->user(), $request->validated());

        return response()->json([
            'data' => $this->pushSettings->extractPushSettingsForResponse($push),
        ]);
    }
}
