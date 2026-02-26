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
    ) {
    }

    public function show(): JsonResponse
    {
        $push = $this->pushSettings->currentPushConfig();

        return response()->json([
            'data' => $this->pushSettings->extractPushSettingsForResponse($push),
        ]);
    }

    public function update(TenantPushSettingsRequest $request): JsonResponse
    {
        $incoming = $request->validated();
        $current = $this->pushSettings->currentPushConfig();

        if (! array_key_exists('max_ttl_days', $incoming) && ! array_key_exists('max_ttl_days', $current)) {
            // Keep legacy wrapper behavior while persistence moves to kernel namespace patching.
            $incoming['max_ttl_days'] = 7;
        }

        $push = $this->pushSettings->patchPushConfig($request->user(), $incoming);

        return response()->json([
            'data' => $this->pushSettings->extractPushSettingsForResponse($push),
        ]);
    }
}
