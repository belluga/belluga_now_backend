<?php

declare(strict_types=1);

namespace Belluga\Settings\Http\Api\v1\Controllers\Tenant;

use Belluga\Settings\Application\SettingsKernelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsKernelController
{
    public function __construct(private readonly SettingsKernelService $service)
    {
    }

    public function schema(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->schema('tenant', $request->user()),
        ]);
    }

    public function values(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->values('tenant', $request->user()),
        ]);
    }

    public function patch(Request $request, string $firstParam, ?string $secondParam = null): JsonResponse
    {
        $namespace = $secondParam ?? $firstParam;

        $payload = $request->json()->all();

        if (! is_array($payload) || array_is_list($payload)) {
            return response()->json([
                'message' => 'The payload must be an object/map.',
                'errors' => [
                    'payload' => ['The payload must be an object/map.'],
                ],
            ], 422);
        }

        return response()->json([
            'data' => $this->service->patchNamespace('tenant', $request->user(), $namespace, $payload),
        ]);
    }
}
