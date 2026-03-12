<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Tenants\TenantAppDomainManagementService;
use App\Http\Api\v1\Requests\TenantAppDomainRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class TenantAppDomainController extends Controller
{
    public function __construct(
        private readonly TenantAppDomainManagementService $appDomainService,
        private readonly TelemetryEmitter $telemetry
    ) {}

    public function index(): JsonResponse
    {
        $tenant = Tenant::resolve();

        return response()->json([
            'app_domains' => $this->appDomainService->list($tenant),
        ]);
    }

    public function store(TenantAppDomainRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $appDomain = $request->validated()['app_domain'];
        $domains = $this->appDomainService->add($tenant, $appDomain);

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'app_domain_added',
                userId: (string) $user->_id,
                properties: [
                    'domain' => $appDomain,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'message' => 'App domains added successfully.',
            'app_domains' => $domains,
        ]);
    }

    public function destroy(TenantAppDomainRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $appDomain = $request->validated()['app_domain'];
        $domains = $this->appDomainService->remove($tenant, $appDomain);

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'app_domain_removed',
                userId: (string) $user->_id,
                properties: [
                    'domain' => $appDomain,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'message' => 'App domains deleted successfully.',
            'app_domains' => $domains,
        ]);
    }
}
