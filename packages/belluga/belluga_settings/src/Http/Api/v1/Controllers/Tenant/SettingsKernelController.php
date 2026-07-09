<?php

declare(strict_types=1);

namespace Belluga\Settings\Http\Api\v1\Controllers\Tenant;

use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Models\Landlord\Tenant;
use Belluga\Settings\Application\SettingsKernelService;
use Belluga\Settings\Exceptions\SettingsNamespaceNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsKernelController
{
    public function __construct(
        private readonly SettingsKernelService $service,
        private readonly TenantEnvironmentSnapshotService $tenantEnvironmentSnapshotService,
    ) {}

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

        try {
            $data = $this->service->patchNamespace('tenant', $request->user(), $namespace, $payload);
            $tenant = Tenant::resolve();
            $this->tenantEnvironmentSnapshotService->repair(
                $tenant->fresh() ?? $tenant,
                'tenant_settings_namespace_updated_sync',
                [
                    'trigger' => 'tenant_settings_patch',
                    'namespace' => $namespace,
                    'changed_fields' => array_keys($payload),
                ],
            );

            return response()->json([
                'data' => $data,
            ]);
        } catch (SettingsNamespaceNotFoundException) {
            abort(404, 'Settings namespace not found.');
        }
    }
}
