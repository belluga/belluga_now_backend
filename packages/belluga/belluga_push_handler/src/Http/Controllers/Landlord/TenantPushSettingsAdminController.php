<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Http\Requests\TenantPushSettingsRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantPushSettingsAdminController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {
    }

    public function show(string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        try {
            $push = $this->pushSettings->currentPushConfig();

            return response()->json(['data' => $this->pushSettings->extractPushSettingsForResponse($push)]);
        } finally {
            $tenant->forgetCurrent();
        }
    }

    public function update(TenantPushSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();
        $incoming = $request->validated();

        try {
            $current = $this->pushSettings->currentPushConfig();
            if (! array_key_exists('max_ttl_days', $incoming) && ! array_key_exists('max_ttl_days', $current)) {
                // Keep legacy wrapper behavior while persistence moves to kernel namespace patching.
                $incoming['max_ttl_days'] = 7;
            }

            $push = $this->pushSettings->patchPushConfig($request->user(), $incoming);

            return response()->json(['data' => $this->pushSettings->extractPushSettingsForResponse($push)]);
        } finally {
            $tenant->forgetCurrent();
        }
    }
}
