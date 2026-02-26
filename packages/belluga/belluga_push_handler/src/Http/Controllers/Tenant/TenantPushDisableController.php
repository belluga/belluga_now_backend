<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushDisableController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $settings = TenantPushSettings::current();
        if (! $settings) {
            return response()->json([
                'message' => 'Push settings are not configured.',
            ], 404);
        }

        $updated = $this->pushSettings->patchPushConfig($request->user(), [
            'enabled' => false,
        ]);

        return response()->json([
            'data' => $updated,
        ]);
    }
}
