<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantFirebaseSettingsRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;

class TenantFirebaseSettingsController
{
    public function show(): JsonResponse
    {
        $settings = TenantPushSettings::current();
        $firebase = is_array($settings?->firebase ?? null) ? $settings->firebase : [];

        return response()->json(['data' => $firebase]);
    }

    public function update(TenantFirebaseSettingsRequest $request): JsonResponse
    {
        $firebase = $request->validated()['firebase'] ?? [];

        $settings = TenantPushSettings::current();
        if (! $settings) {
            $settings = TenantPushSettings::create(['firebase' => $firebase]);
        } else {
            $settings->fill(['firebase' => $firebase]);
            $settings->save();
        }

        return response()->json(['data' => $settings->firebase ?? []]);
    }
}
