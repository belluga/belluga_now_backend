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
        $roles = $this->tenantRoleService->paginate(
            Tenant::current(),
            $request->boolean('archived')
        );

        return response()->json($roles);
    }

    public function store(TenantRoleStoreRequest $request): JsonResponse
    {
        $role = $this->tenantRoleService->create(Tenant::current(), $request->validated());

        return response()->json([
            'data' => $role,
        ], 201);
    }

    public function show(string $role_id): JsonResponse
    {
        $role = $this->tenantRoleService->find(Tenant::current(), $role_id);

        return response()->json([
            'data' => $role,
        ]);
    }

    public function update(TenantRoleUpdateRequest $request, string $role_id): JsonResponse
    {
        $updated = $this->tenantRoleService->update(
            Tenant::current(),
            $role_id,
            $request->validated()
        );

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function destroy(TenantRoleDestroyRequest $request, string $role_id): JsonResponse
    {
        $this->tenantRoleService->delete(
            Tenant::current(),
            $role_id,
            $request->validated()['background_role_id']
        );

        return response()->json();
    }

    public function forceDestroy(string $role_id): JsonResponse
    {
        $this->tenantRoleService->forceDelete(Tenant::current(), $role_id);

        return response()->json();
    }

    public function restore(string $role_id): JsonResponse
    {
        $role = $this->tenantRoleService->restore(Tenant::current(), $role_id);

        return response()->json([
            'data' => $role,
        ]);
    }
}
