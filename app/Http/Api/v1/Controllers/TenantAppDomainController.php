<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Tenants\TenantAppDomainManagementService;
use App\Http\Api\v1\Requests\TenantAppDomainRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class TenantAppDomainController extends Controller
{
    public function __construct(
        private readonly TenantAppDomainManagementService $appDomainService
    ) {
    }

    public function index(): JsonResponse
    {
        $tenant = $this->resolveTenant();

        return response()->json([
            'app_domains' => $this->appDomainService->list($tenant),
        ]);
    }

    public function store(TenantAppDomainRequest $request): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $domains = $this->appDomainService->add($tenant, $request->validated()['app_domain']);

        return response()->json([
            'message' => 'App domains added successfully.',
            'app_domains' => $domains,
        ]);
    }

    public function destroy(TenantAppDomainRequest $request): JsonResponse
    {
        $tenant = $this->resolveTenant();
        $domains = $this->appDomainService->remove($tenant, $request->validated()['app_domain']);

        return response()->json([
            'message' => 'App domains deleted successfully.',
            'app_domains' => $domains,
        ]);
    }

    private function resolveTenant(): Tenant
    {
        $tenant = Tenant::current();

        if (! $tenant) {
            abort(422, 'Tenant context not available.');
        }

        return $tenant;
    }
}
