<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantPushMessageTypesRequest;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushMessageTypesController
{
    public function show(): JsonResponse
    {
        $types = TenantPushSettings::current()?->getPushMessageTypes() ?? [];

        return response()->json([
            'data' => is_array($types) ? $types : [],
        ]);
    }

    public function update(TenantPushMessageTypesRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $settings = TenantPushSettings::current();
        $types = $this->mergeTypes(
            $settings?->getPushMessageTypes() ?? [],
            $payload
        );

        if (! $settings) {
            $settings = TenantPushSettings::create([
                'push' => [
                    'message_types' => $types,
                    'max_ttl_days' => 7,
                ],
            ]);
        } else {
            $push = $settings->getPushConfig();
            $push['message_types'] = $types;
            $settings->fill(['push' => $push]);
            $settings->save();
        }

        return response()->json([
            'data' => $settings->getPushMessageTypes(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string', 'distinct'],
        ]);

        $settings = TenantPushSettings::current();
        $types = $this->indexTypes($settings?->getPushMessageTypes() ?? []);
        foreach ($payload['keys'] as $key) {
            if (! isset($types[$key])) {
                continue;
            }
            $types[$key]['active'] = false;
        }

        if (! $settings) {
            $settings = TenantPushSettings::create([
                'push' => [
                    'message_types' => array_values($types),
                    'max_ttl_days' => 7,
                ],
            ]);
        } else {
            $push = $settings->getPushConfig();
            $push['message_types'] = array_values($types);
            $settings->fill(['push' => $push]);
            $settings->save();
        }

        return response()->json([
            'data' => $settings->getPushMessageTypes(),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeTypes(array $existing, array $incoming): array
    {
        $indexed = $this->indexTypes($existing);
        foreach ($incoming as $type) {
            if (! is_array($type)) {
                continue;
            }
            $key = $type['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (! array_key_exists('active', $type)) {
                $type['active'] = true;
            }
            $indexed[$key] = $type;
        }

        return array_values($indexed);
    }

    /**
     * @param array<int, array<string, mixed>> $types
     * @return array<string, array<string, mixed>>
     */
    private function indexTypes(array $types): array
    {
        $indexed = [];
        foreach ($types as $type) {
            if (! is_array($type)) {
                continue;
            }
            $key = $type['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $indexed[$key] = $type;
        }

        return $indexed;
    }
}
