<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Belluga\PushHandler\Http\Requests\TenantFirebaseSettingsRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantFirebaseSettingsAdminController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly PushTenantContextContract $tenantContext
    ) {}

    public function show(string $tenant_slug): JsonResponse
    {
        return $this->tenantContext->runForTenantSlug($tenant_slug, function () {
            $firebase = $this->pushSettings->currentFirebaseConfig();

            return response()->json(['data' => $firebase]);
        });
    }

    public function update(TenantFirebaseSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        return $this->tenantContext->runForTenantSlug($tenant_slug, function () use ($request) {
            $incoming = $request->validated();
            $firebase = $this->pushSettings->patchFirebaseConfig($request->user(), $incoming);

            return response()->json(['data' => $firebase]);
        });
    }
}
