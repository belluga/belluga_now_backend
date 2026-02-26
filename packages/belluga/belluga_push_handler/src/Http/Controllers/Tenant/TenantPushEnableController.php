<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
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
        $settings = TenantPushSettings::current();
        $push = $this->pushSettings->currentPushConfig();
        if (! $settings || ! is_array($push)) {
            return $this->notConfiguredResponse();
        }

        $firebase = $settings->firebase ?? null;
        if (! $this->hasFirebaseConfig($firebase)) {
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

    private function hasFirebaseConfig(mixed $firebase): bool
    {
        if (! is_array($firebase)) {
            return false;
        }

        $required = ['apiKey', 'appId', 'projectId', 'messagingSenderId', 'storageBucket'];
        foreach ($required as $key) {
            if (! isset($firebase[$key]) || ! is_string($firebase[$key]) || $firebase[$key] === '') {
                return false;
            }
        }

        return true;
    }
}
