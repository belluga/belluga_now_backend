<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\TenantUserManagementService;
use App\Application\Accounts\TenantUserQueryService;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TenantUsersController extends Controller
{
    public function __construct(
        private readonly TenantUserManagementService $tenantUserService,
        private readonly TenantUserQueryService $tenantUserQueryService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function index(Request $request): LengthAwarePaginator
    {
        return $this->tenantUserQueryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            (int) $request->get('per_page', 15)
        );
    }

    public function show(Request $request): JsonResponse
    {
        $user_id = (string) $request->route('user_id');
        $user = $this->tenantUserService->find($user_id);

        return response()->json([
            'data' => $user,
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $this->tenantUserService->restore((string) $request->route('user_id'));

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_user_restored',
                userId: (string) $user->_id,
                properties: [
                    'target_user_id' => (string) $request->route('user_id'),
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function destroy(Request $request): JsonResponse
    {
        $user_id = (string) $request->route('user_id');
        $this->tenantUserService->delete($user_id);

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_user_deleted',
                userId: (string) $user->_id,
                properties: [
                    'target_user_id' => $user_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $user_id = (string) $request->route('user_id');
        $this->tenantUserService->forceDelete($user_id);

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'tenant_user_force_deleted',
                userId: (string) $user->_id,
                properties: [
                    'target_user_id' => $user_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json();
    }
}
