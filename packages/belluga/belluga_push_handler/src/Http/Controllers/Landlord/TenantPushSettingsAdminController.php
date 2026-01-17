<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Landlord;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Http\Requests\TenantPushSettingsRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;

class TenantPushSettingsAdminController
{
    public function show(string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        $settings = TenantPushSettings::current();

        $tenant->forgetCurrent();

        return response()->json(['data' => $this->extractPushSettings($settings)]);
    }

    public function update(TenantPushSettingsRequest $request, string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::query()->where('slug', $tenant_slug)->firstOrFail();
        $tenant->makeCurrent();

        $settings = TenantPushSettings::current();
        $pushConfig = $settings?->getPushConfig() ?? [];
        $incomingPush = $request->validated()['push'] ?? [];
        $maxTtlDays = $incomingPush['max_ttl_days'] ?? $pushConfig['max_ttl_days'] ?? 7;
        unset($incomingPush['max_ttl_days']);

        $pushConfig = array_replace($pushConfig, $incomingPush);
        $pushConfig['max_ttl_days'] = $maxTtlDays;

        $payload = [
            'push' => $pushConfig,
        ];

        if (! $settings) {
            $settings = TenantPushSettings::create($payload);
        } else {
            $settings->fill($payload);
            $settings->save();
        }

        $tenant->forgetCurrent();

        return response()->json(['data' => $this->extractPushSettings($settings)]);
    }

    /**
     * @param TenantPushSettings|null $settings
     * @return array<string, mixed>
     */
    private function extractPushSettings(?TenantPushSettings $settings): array
    {
        if (! $settings) {
            return [];
        }

        $push = $settings->getPushConfig();
        unset($push['message_routes'], $push['message_types']);
        $push['max_ttl_days'] = $settings->getPushMaxTtlDays();

        return $push;
    }
}
