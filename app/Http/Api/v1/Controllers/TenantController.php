<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\LandlordTenants\TenantLifecycleService;
use App\Http\Api\v1\Requests\TenantStoreRequest;
use App\Http\Api\v1\Requests\TenantUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantLifecycleService $tenantService
    ) {}

    public function index(Request $request): LengthAwarePaginator
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        return $this->tenantService->paginate(
            $user,
            $request->boolean('archived'),
            (int) $request->get('per_page', 15)
        );
    }

    public function store(TenantStoreRequest $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        $result = $this->tenantService->create($request->validated(), $user);
        $tenant = $result['tenant'];
        $role = $result['role'];

        return response()->json([
            'data' => [
                ...$tenant->attributesToArray(),
                'role_admin_id' => $role->id,
            ],
        ], 201);
    }

    public function show(string $tenant_slug): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        $tenant = $this->tenantService->findAccessibleBySlug($user, $tenant_slug);

        return response()->json([
            'data' => $tenant,
        ]);
    }

    public function update(TenantUpdateRequest $request, string $tenant_slug): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        $tenant = $this->tenantService->findAccessibleBySlug($user, $tenant_slug);
        $updated = $this->tenantService->update($tenant, $request->validated());

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        $tenant = $this->tenantService->restore($user, (string) $request->route('tenant_slug'));

        return response()->json([
            'data' => $tenant,
        ]);
    }

    public function destroy(string $tenant_slug): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        $this->tenantService->delete($user, $tenant_slug);

        return response()->json([]);
    }

    public function forceDestroy(string $tenant_slug): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = auth()->guard('sanctum')->user();

        $this->tenantService->forceDelete($user, $tenant_slug);

        return response()->json();
    }
}
