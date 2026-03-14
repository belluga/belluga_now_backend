<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Belluga\PushHandler\Http\Requests\TenantPushSettingsRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantPushSettingsAdminController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly PushTenantContextContract $tenantContext
    ) {}

    public function show(string $tenant_slug): JsonResponse
    {
        return $this->tenantContext->runForTenantSlug($tenant_slug, function () {
            $push = $this->pushSettings->resolvedPushConfig();

            return response()->json(['data' => $this->pushSettings->extractPushSettingsForResponse($push)]);
        });
    }

    public function update(TenantPushSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $incoming = $request->validated();

        return $this->tenantContext->runForTenantSlug($tenant_slug, function () use ($request, $incoming) {
            $push = $this->pushSettings->patchPushConfig($request->user(), $incoming);

            return response()->json(['data' => $this->pushSettings->extractPushSettingsForResponse($push)]);
        });
    }
}
