<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\RolesStoreRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

class RolesController extends Controller
{
    public function store(RolesStoreRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $account_slug = $request->route('account_slug');

        $account = $user->accountsOwner()
            ->where('slug', $account_slug)
            ->firstOrFail();

        $userRole = UserRole::where('account_id', $account->id)
            ->where('user_id', $user->id)
            ->with('role')
            ->firstOr(function () {
                abort(403, 'Unauthorized.');
            });

        if (!$userRole->role->hasPermissionTo('role.create')) {
            abort(403, 'You do not have permission to create roles.');
        }

        try {
            DB::beginTransaction();

            $role = $account->rolesOwner()->create(
                [
                    ...$request->validated(),
                    'owner_id' => $user->id,
                    'owner_type' => get_class($user)
                ]
            );

            DB::commit();

            return response()->json([
                'data' => $role
            ], 201);

        } catch (BulkWriteException $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'E11000')) {
                abort(422, 'Role already exists.');
            }

            abort(422, 'Something went wrong when trying to create the role.');
        }
    }

//    public function show(string $tenant_slug): JsonResponse
//    {
//
//        $user = auth()->guard('sanctum')->user();
//        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();
//
//        if($tenant){
//            return response()->json($tenant);
//        }
//
//        return response()->json([
//            'message' => "Tenant não encontrado.",
//            'errors' => [
//                'tenant ' => ["O tenant solicitado não existe."
//                ]
//            ]
//        ],
//        404);
//    }

//    public function update(TenantUpdateRequest $request, string $tenant_slug): JsonResponse
//    {
//        $user = auth()->guard('sanctum')->user();
//        $this->tenant = $user->tenants()->where('slug', $tenant_slug)->first();
//        $params_to_update = $this->filterGuardedParameters($request->validated());
//        $this->tenant->update($params_to_update);
//
//        return response()->json([
//            'data' => $this->tenant
//        ], 200);
//    }

//    public function restore(string $tenant_slug): JsonResponse
//    {
//        $user = auth()->guard('sanctum')->user();
//        $tenant = $user->tenants()->onlyTrashed()->where('slug', $tenant_slug)->first();
//        $tenant->restore();
//
//        return response()->json([]);
//    }

//    public function destroy(string $tenant_slug): JsonResponse
//    {
//        $user = auth()->guard('sanctum')->user();
//        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();
//
//        $tenant->delete();
//
//        return response()->json([]);
//    }

//    public function forceDestroy(string $tenant_slug): JsonResponse
//    {
//        $tenant = Tenant::onlyTrashed()
//            ->where('slug', $tenant_slug)
//            ->firstOrFail();
//
//        DB::beginTransaction();
//        try {
//            $tenant->domains()->delete();
//            $tenant->users()->detach();
//            $tenant->forceDelete();;
//        } catch (\Exception $e) {
//            DB::rollBack();
//
//            return response()->json([
//                'message' => "Erro ao desfazer relacionamentos.",
//                'errors' => [
//                    'tenant' => ["Ocorreu um erro ao desfazer relacionamentos com o tenant. Tente novamente mais tarde."]
//                ]
//            ], 422);
//        }
//
//        DB::commit();
//
//        return response()->json();
//    }

//    protected function filterGuardedParameters(array $received_params): array {
//        $guarded = $this->tenant->getGuarded();
//
//        return collect($received_params)
//            ->reject(fn ($value, $key) => in_array($key, $guarded) )
//            ->toArray();
//    }
}
