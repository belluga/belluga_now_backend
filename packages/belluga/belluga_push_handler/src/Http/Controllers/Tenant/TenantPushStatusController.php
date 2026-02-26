<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Services\PushCredentialService;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;

class TenantPushStatusController
{
    public function __construct(
        private readonly PushCredentialService $credentialService,
        private readonly PushSettingsKernelBridge $pushSettings
    ) {
    }

    public function show(): JsonResponse
    {
        $settings = TenantPushSettings::current();
        $push = $this->pushSettings->currentPushConfig();

        if (! $settings || ! $this->isConfigured($settings, $push)) {
            return response()->json(['status' => 'not_configured']);
        }

        try {
            $credential = $this->credentialService->current();
        } catch (MultiplePushCredentialsException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (! $credential) {
            return response()->json(['status' => 'not_configured']);
        }

        $hasAcceptedDelivery = PushDeliveryLog::query()
            ->where('status', 'accepted')
            ->exists();

        return response()->json([
            'status' => $hasAcceptedDelivery ? 'active' : 'pending_tests',
        ]);
    }

    /**
     * @param array<string, mixed> $push
     */
    private function isConfigured(TenantPushSettings $settings, array $push): bool
    {
        if (! ($push['enabled'] ?? false)) {
            return false;
        }

        $firebase = $settings->getAttribute('firebase') ?? [];
        $requiredKeys = [
            'apiKey',
            'appId',
            'projectId',
            'messagingSenderId',
            'storageBucket',
        ];

        foreach ($requiredKeys as $key) {
            $value = $firebase[$key] ?? null;
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }
}
