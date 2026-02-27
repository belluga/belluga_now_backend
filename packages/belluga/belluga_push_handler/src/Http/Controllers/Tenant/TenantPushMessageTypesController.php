<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\TenantPushMessageTypesRequest;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPushMessageTypesController
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings
    ) {
    }

    public function show(): JsonResponse
    {
        $types = $this->pushSettings->currentMessageTypes();

        return response()->json([
            'data' => is_array($types) ? $types : [],
        ]);
    }

    public function update(TenantPushMessageTypesRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $types = $this->mergeTypes(
            $this->pushSettings->currentMessageTypes(),
            $payload
        );

        $updatedTypes = $this->pushSettings->patchMessageTypes($request->user(), $types);

        return response()->json([
            'data' => $updatedTypes,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string', 'distinct'],
        ]);

        $types = $this->indexTypes($this->pushSettings->currentMessageTypes());
        foreach ($payload['keys'] as $key) {
            if (! isset($types[$key])) {
                continue;
            }
            $types[$key]['active'] = false;
        }

        $updatedTypes = $this->pushSettings->patchMessageTypes($request->user(), array_values($types));

        return response()->json([
            'data' => $updatedTypes,
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
