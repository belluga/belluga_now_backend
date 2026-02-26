<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Http\Requests\TenantFirebaseSettingsRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;

class TenantFirebaseSettingsAdminController
{
    public function show(string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        $settings = TenantPushSettings::current();
        $firebase = is_array($settings?->firebase ?? null) ? $settings->firebase : [];

        $tenant->forgetCurrent();

        return response()->json(['data' => $firebase]);
    }

    public function update(TenantFirebaseSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        $incoming = $request->validated();
        $settings = TenantPushSettings::current();
        $firebase = is_array($settings?->firebase ?? null) ? $settings->firebase : [];
        $firebase = array_replace($firebase, $incoming);

        if (! $settings) {
            $settings = TenantPushSettings::create(['firebase' => $firebase]);
        } else {
            $settings->fill(['firebase' => $firebase]);
            $settings->save();
        }

        $tenant->forgetCurrent();

        return response()->json(['data' => $settings->firebase ?? []]);
    }
}
