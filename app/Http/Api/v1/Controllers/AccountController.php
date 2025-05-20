<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\AccountStoreRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountController extends Controller
{

//    protected ?Tenant $tenant;

//    public function index(Request $request): LengthAwarePaginator
//    {
//        $user = auth()->guard('sanctum')->user();
//        return $user->tenants()->with('domains')->paginate($request->get('per_page', 15));
//    }

    public function store(AccountStoreRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        try {
            $account = $user->accountsOwner()->create($request->validated());

            return response()->json([
                'data' => $account
            ], 201);

        } catch (BulkWriteException $e) {
            if (str_contains($e->getMessage(), 'E11000')) {
                return response()->json([
                    'message' => 'Account already exists.',
                    'errors' => ['account' => ["Account already exists."]]
                ], 422);
            }

            return response()->json([
                'message' => "Something went wrong when trying to create the tenant.",
                'errors' => ['account' => ["Something went wrong when trying to create the account."]]
            ], 422);
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
