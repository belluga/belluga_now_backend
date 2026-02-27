<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Http\Requests\TenantFirebaseSettingsRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantFirebaseSettingsAdminController
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
            $firebase = $this->pushSettings->currentFirebaseConfig();

            return response()->json(['data' => $firebase]);
        } finally {
            $tenant->forgetCurrent();
        }
    }

    public function update(TenantFirebaseSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        try {
            $incoming = $request->validated();
            $firebase = $this->pushSettings->patchFirebaseConfig($request->user(), $incoming);

            return response()->json(['data' => $firebase]);
        } finally {
            $tenant->forgetCurrent();
        }
    }
}
