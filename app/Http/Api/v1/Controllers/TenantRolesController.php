<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Tenants\TenantRoleManagementService;
use App\Http\Api\v1\Requests\TenantRoleDestroyRequest;
use App\Http\Api\v1\Requests\TenantRoleStoreRequest;
use App\Http\Api\v1\Requests\TenantRoleUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantRolesController extends Controller
{
    public function __construct(
        private readonly TenantRoleManagementService $tenantRoleService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $roles = $this->tenantRoleService->paginate(
            $tenant,
            $request->boolean('archived')
        );

        return response()->json($roles);
    }

    public function store(TenantRoleStoreRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $role = $this->tenantRoleService->create($tenant, $request->validated());

        return response()->json([
            'data' => $role,
        ], 201);
    }

    public function show(string $tenant_domain, string $role_id): JsonResponse
    {
        $tenant = Tenant::resolve();
        $role = $this->tenantRoleService->find($tenant, $role_id);

        return response()->json([
            'data' => $role,
        ]);
    }

    public function update(
        TenantRoleUpdateRequest $request,
        string $tenant_domain,
        string $role_id
    ): JsonResponse
    {
        $tenant = Tenant::resolve();
        $updated = $this->tenantRoleService->update(
            $tenant,
            $role_id,
            $request->validated()
        );

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function destroy(
        TenantRoleDestroyRequest $request,
        string $tenant_domain,
        string $role_id
    ): JsonResponse
    {
        $tenant = Tenant::resolve();
        $this->tenantRoleService->delete(
            $tenant,
            $role_id,
            $request->validated()['background_role_id']
        );

        return response()->json();
    }

    public function forceDestroy(string $tenant_domain, string $role_id): JsonResponse
    {
        $tenant = Tenant::resolve();
        $this->tenantRoleService->forceDelete($tenant, $role_id);

        return response()->json();
    }

    public function restore(string $tenant_domain, string $role_id): JsonResponse
    {
        $tenant = Tenant::resolve();
        $role = $this->tenantRoleService->restore($tenant, $role_id);

        return response()->json([
            'data' => $role,
        ]);
    }
}
