<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushDisableController
{
    public function __invoke(Request $request): JsonResponse
    {
        $settings = TenantPushSettings::current();
        if (! $settings) {
            return response()->json([
                'message' => 'Push settings are not configured.',
            ], 404);
        }

        $push = $settings->push ?? [];
        if (! is_array($push)) {
            $push = [];
        }

        $push['enabled'] = false;
        $settings->fill(['push' => $push]);
        $settings->save();

        return response()->json([
            'data' => is_array($settings->push ?? null) ? $settings->push : [],
        ]);
    }
}
