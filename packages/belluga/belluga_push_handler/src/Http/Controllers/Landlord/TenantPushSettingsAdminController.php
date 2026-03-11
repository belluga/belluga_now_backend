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
            $push = $this->pushSettings->currentPushConfig();

            return response()->json(['data' => $this->pushSettings->extractPushSettingsForResponse($push)]);
        });
    }

    public function update(TenantPushSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $incoming = $request->validated();

        return $this->tenantContext->runForTenantSlug($tenant_slug, function () use ($request, $incoming) {
            $current = $this->pushSettings->currentPushConfig();
            if (! array_key_exists('max_ttl_days', $incoming) && ! array_key_exists('max_ttl_days', $current)) {
                // Keep legacy wrapper behavior while persistence moves to kernel namespace patching.
                $incoming['max_ttl_days'] = 7;
            }

            $push = $this->pushSettings->patchPushConfig($request->user(), $incoming);

            return response()->json(['data' => $this->pushSettings->extractPushSettingsForResponse($push)]);
        });
    }
}
