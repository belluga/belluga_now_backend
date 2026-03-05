<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushEnableController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $push = $this->pushSettings->currentPushConfig();
        if ($push === []) {
            return $this->notConfiguredResponse();
        }

        $firebase = $this->pushSettings->currentFirebaseConfig();
        if (! $this->pushSettings->hasRequiredFirebaseConfig($firebase)) {
            return $this->notConfiguredResponse();
        }

        $updated = $this->pushSettings->patchPushConfig($request->user(), [
            'enabled' => true,
        ]);

        return response()->json([
            'data' => $updated,
        ]);
    }

    private function notConfiguredResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Push settings are not configured.',
            'errors' => [
                'firebase' => ['Firebase config is required before enabling push.'],
                'push' => ['Push config is required before enabling push.'],
            ],
        ], 422);
    }
}
