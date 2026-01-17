<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantPushSettingsRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;

class TenantPushSettingsController
{
    public function show(): JsonResponse
    {
        $settings = TenantPushSettings::current();

        return response()->json([
            'data' => $this->extractPushSettings($settings),
        ]);
    }

    public function update(TenantPushSettingsRequest $request): JsonResponse
    {
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

        return response()->json([
            'data' => $this->extractPushSettings($settings),
        ]);
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
