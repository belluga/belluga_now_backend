<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\TenantStoreRequest;
use App\Http\Api\v1\Requests\TenantUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

class TenantController extends Controller
{

    protected ?Tenant $tenant;

    public function index(Request $request): LengthAwarePaginator
    {
        $user = auth()->guard('sanctum')->user();
        return $user->tenants()->with('domains')->paginate($request->get('per_page', 15));
    }

    public function store(TenantStoreRequest $request): JsonResponse
    {

        $user = auth()->guard('sanctum')->user();

        try {
            $tenant = $user->tenants()->create($request->validated());

            return response()->json([
                'data' => $tenant
            ], 201);

        } catch (BulkWriteException $e) {
            if (str_contains($e->getMessage(), 'E11000')) {
                abort(422, "Tenant already exists.");
            }

            abort(422, "Something went wrong when trying to create the tenant.");;
        }
    }

    public function show(string $tenant_slug): JsonResponse
    {

        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();

        if($tenant){
            return response()->json([
                "data" => $tenant
            ]);
        }

        return response()->json([
            'message' => "Tenant não encontrado.",
            'errors' => [
                'tenant ' => ["O tenant solicitado não existe."
                ]
            ]
        ],
        404);
    }

    public function update(TenantUpdateRequest $request, string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $this->tenant = $user->tenants()->where('slug', $tenant_slug)->first();
        $params_to_update = $this->filterGuardedParameters($request->validated());
        $this->tenant->update($params_to_update);

        return response()->json([
            'data' => $this->tenant
        ], 200);
    }

    public function restore(string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->onlyTrashed()->where('slug', $tenant_slug)->first();
        $tenant->restore();

        return response()->json([]);
    }

    public function destroy(string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();

        $tenant->delete();

        return response()->json([]);
    }

    public function forceDestroy(string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::onlyTrashed()
            ->where('slug', $tenant_slug)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $tenant->domains()->delete();
            $tenant->users()->detach();
            $tenant->forceDelete();;
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => "Erro ao desfazer relacionamentos.",
                'errors' => [
                    'tenant' => ["Ocorreu um erro ao desfazer relacionamentos com o tenant. Tente novamente mais tarde."]
                ]
            ], 422);
        }

        DB::commit();

        return response()->json();
    }

    protected function filterGuardedParameters(array $received_params): array {
        $guarded = $this->tenant->getGuarded();

        return collect($received_params)
            ->reject(fn ($value, $key) => in_array($key, $guarded) )
            ->toArray();
    }
}
