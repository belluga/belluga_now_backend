<?php

declare(strict_types=1);

namespace Belluga\Settings\Http\Api\v1\Controllers\Landlord;

use Belluga\Settings\Application\SettingsKernelService;
use Belluga\Settings\Contracts\TenantScopeContextContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSettingsKernelController
{
    public function __construct(
        private readonly SettingsKernelService $service,
        private readonly TenantScopeContextContract $tenantContext,
    ) {}

    public function schema(Request $request, string $tenant_slug): JsonResponse
    {
        $data = $this->tenantContext->runForTenantSlug($tenant_slug, function () use ($request): array {
            return $this->service->schema('tenant', $request->user());
        });

        return response()->json(['data' => $data]);
    }

    public function values(Request $request, string $tenant_slug): JsonResponse
    {
        $data = $this->tenantContext->runForTenantSlug($tenant_slug, function () use ($request): array {
            return $this->service->values('tenant', $request->user());
        });

        return response()->json(['data' => $data]);
    }

    public function patch(Request $request, string $tenant_slug, string $namespace): JsonResponse
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || array_is_list($payload)) {
            return response()->json([
                'message' => 'The payload must be an object/map.',
                'errors' => [
                    'payload' => ['The payload must be an object/map.'],
                ],
            ], 422);
        }

        $data = $this->tenantContext->runForTenantSlug($tenant_slug, function () use ($request, $namespace, $payload): array {
            return $this->service->patchNamespace('tenant', $request->user(), $namespace, $payload);
        });

        return response()->json(['data' => $data]);
    }
}
