<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
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
        private readonly TenantRoleManagementService $tenantRoleService,
        private readonly TelemetryEmitter $telemetry
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

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_role_created',
                userId: (string) $user->_id,
                properties: [
                    'role_id' => (string) $role->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $role,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $role_id = (string) $request->route('role_id');
        $tenant = Tenant::resolve();
        $role = $this->tenantRoleService->find($tenant, $role_id);

        return response()->json([
            'data' => $role,
        ]);
    }

    public function update(
        TenantRoleUpdateRequest $request
    ): JsonResponse
    {
        $role_id = (string) $request->route('role_id');
        $tenant = Tenant::resolve();
        $validated = $request->validated();
        $updated = $this->tenantRoleService->update(
            $tenant,
            $role_id,
            $validated
        );

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_role_updated',
                userId: (string) $user->_id,
                properties: [
                    'role_id' => $role_id,
                    'changed_fields' => array_keys($validated),
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function destroy(
        TenantRoleDestroyRequest $request
    ): JsonResponse
    {
        $role_id = (string) $request->route('role_id');
        $tenant = Tenant::resolve();
        $this->tenantRoleService->delete(
            $tenant,
            $role_id,
            $request->validated()['background_role_id']
        );

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_role_deleted',
                userId: (string) $user->_id,
                properties: [
                    'role_id' => $role_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $role_id = (string) $request->route('role_id');
        $tenant = Tenant::resolve();
        $this->tenantRoleService->forceDelete($tenant, $role_id);

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_role_force_deleted',
                userId: (string) $user->_id,
                properties: [
                    'role_id' => $role_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function restore(Request $request): JsonResponse
    {
        $role_id = (string) $request->route('role_id');
        $tenant = Tenant::resolve();
        $role = $this->tenantRoleService->restore($tenant, $role_id);

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_role_restored',
                userId: (string) $user->_id,
                properties: [
                    'role_id' => (string) $role->_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $role,
        ]);
    }
}
