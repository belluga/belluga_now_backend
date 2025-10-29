<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Accounts\TenantUserManagementService;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TenantUsersController extends Controller
{
    public function __construct(
        private readonly TenantUserManagementService $tenantUserService
    ) {
    }

    public function index(Request $request): LengthAwarePaginator
    {
        return $this->tenantUserService->paginate(
            $request->boolean('archived'),
            (int) $request->get('per_page', 15)
        );
    }

    public function show(string $user_id): JsonResponse
    {
        $user = $this->tenantUserService->find($user_id);

        return response()->json([
            'data' => $user,
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $this->tenantUserService->restore((string) $request->route('user_id'));

        return response()->json();
    }

    public function destroy(string $user_id): JsonResponse
    {
        $this->tenantUserService->delete($user_id);

        return response()->json();
    }

    public function forceDestroy(string $user_id): JsonResponse
    {
        $this->tenantUserService->forceDelete($user_id);

        return response()->json();
    }
}
