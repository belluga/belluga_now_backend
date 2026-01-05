<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushEnableController
{
    public function __invoke(Request $request): JsonResponse
    {
        $settings = TenantPushSettings::current();
        if (! $settings) {
            return $this->notConfiguredResponse();
        }

        $firebase = $settings->firebase ?? null;
        $push = $settings->push ?? null;
        if (! $this->hasFirebaseConfig($firebase) || ! is_array($push)) {
            return $this->notConfiguredResponse();
        }

        $push['enabled'] = true;
        $settings->fill(['push' => $push]);
        $settings->save();

        return response()->json([
            'data' => is_array($settings->push ?? null) ? $settings->push : [],
        ]);
    }

    private function notConfiguredResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Push settings are not configured.',
            'errors' => [
                'firebase' => ['Firebase config is required before enabling push.'],
                'push' => ['Push config is required before enabling push.'],
            ],
        ], 422);
    }

    private function hasFirebaseConfig(mixed $firebase): bool
    {
        if (! is_array($firebase)) {
            return false;
        }

        $required = ['apiKey', 'appId', 'projectId', 'messagingSenderId', 'storageBucket'];
        foreach ($required as $key) {
            if (! isset($firebase[$key]) || ! is_string($firebase[$key]) || $firebase[$key] === '') {
                return false;
            }
        }

        return true;
    }
}
