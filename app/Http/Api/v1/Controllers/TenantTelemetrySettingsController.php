<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetrySettingsKernelBridge;
use App\Http\Api\v1\Requests\TelemetrySettingsStoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantTelemetrySettingsController
{
    public function __construct(
        private readonly TelemetrySettingsKernelBridge $telemetrySettings
    ) {}

    public function index(): JsonResponse
    {
        $config = $this->telemetrySettings->currentTelemetryConfig();

        return response()->json([
            'data' => $config['trackers'],
            'available_events' => $this->telemetrySettings->availableEvents(),
        ]);
    }

    public function store(TelemetrySettingsStoreRequest $request): JsonResponse
    {
        $config = $this->telemetrySettings->upsertTracker(
            user: $request->user(),
            tracker: $request->validated()
        );

        return response()->json([
            'data' => $config['trackers'],
            'available_events' => $this->telemetrySettings->availableEvents(),
        ]);
    }

    public function destroy(Request $request, string $tenant_domain, string $type): JsonResponse
    {
        $config = $this->telemetrySettings->removeTracker(
            user: $request->user(),
            type: $type
        );

        return response()->json([
            'data' => $config['trackers'],
            'available_events' => $this->telemetrySettings->availableEvents(),
        ]);
    }
}
