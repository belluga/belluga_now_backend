<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\LandlordTenants\TenantLifecycleService;
use App\Application\Telemetry\TelemetrySettingsKernelBridge;
use App\Http\Api\v1\Requests\TelemetrySettingsStoreRequest;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandlordTenantTelemetrySettingsController
{
    public function __construct(
        private readonly TelemetrySettingsKernelBridge $telemetrySettings,
        private readonly TenantLifecycleService $tenantService,
    ) {}

    public function index(Request $request, string $tenant_slug): JsonResponse
    {
        $tenant = $this->resolveAccessibleTenant($request, $tenant_slug);
        $tenant->makeCurrent();

        try {
            $config = $this->telemetrySettings->currentTelemetryConfig();

            return response()->json([
                'data' => $config['trackers'],
                'available_events' => $this->telemetrySettings->availableEvents(),
            ]);
        } finally {
            $tenant->forgetCurrent();
        }
    }

    public function store(TelemetrySettingsStoreRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = $this->resolveAccessibleTenant($request, $tenant_slug);
        $tenant->makeCurrent();

        try {
            $config = $this->telemetrySettings->upsertTracker(
                user: $request->user(),
                tracker: $request->validated()
            );

            return response()->json([
                'data' => $config['trackers'],
                'available_events' => $this->telemetrySettings->availableEvents(),
            ]);
        } finally {
            $tenant->forgetCurrent();
        }
    }

    public function destroy(Request $request, string $tenant_slug, string $type): JsonResponse
    {
        $tenant = $this->resolveAccessibleTenant($request, $tenant_slug);
        $tenant->makeCurrent();

        try {
            $config = $this->telemetrySettings->removeTracker(
                user: $request->user(),
                type: $type
            );

            return response()->json([
                'data' => $config['trackers'],
                'available_events' => $this->telemetrySettings->availableEvents(),
            ]);
        } finally {
            $tenant->forgetCurrent();
        }
    }

    private function resolveAccessibleTenant(Request $request, string $tenantSlug): Tenant
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        return $this->tenantService->findAccessibleBySlug($user, $tenantSlug);
    }
}
