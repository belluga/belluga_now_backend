<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\TenantStoreRequest;
use App\Http\Api\v1\Requests\TenantUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
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
        return $user->haveAccessTo()
            ->when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->with('domains')->paginate($request->get('per_page', 15));
    }

    public function store(TenantStoreRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        try {
            $tenant = $user->haveAccessTo()->create($request->validated());
        } catch (BulkWriteException $e) {
            abort(422, "Something went wrong when trying to create the tenant.");
        }

        return response()->json([
            'data' => $tenant,
        ], 201);
    }

    public function show(string $tenant_slug): JsonResponse
    {

        $user = auth()->guard('sanctum')->user();
        $tenant = $user->haveAccessTo()->where('slug', $tenant_slug)->first();

        if($tenant){
            return response()->json([
                "data" => $tenant
            ]);
        }

        abort(404, "Tenant não encontrado.");
    }

    public function update(TenantUpdateRequest $request, string $tenant_slug): JsonResponse
    {
        $this->tenant = Tenant::where('slug', $tenant_slug)->first();

        $params_to_update = $this->filterGuardedParameters($request->validated());

        $this->tenant->update($params_to_update);

        return response()->json([
            'data' => $this->tenant
        ], 200);
    }

    public function restore(string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $tenant = $user->haveAccessTo()->onlyTrashed()->where('slug', $tenant_slug)->first();
        $tenant->restore();

        return response()->json([]);
    }

    public function destroy(string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        $tenant = $user->haveAccessTo()->where('slug', $tenant_slug)->first();

        $tenant->delete();

        return response()->json([]);
    }

    public function forceDestroy(string $tenant_slug): JsonResponse
    {
        $tenant = Tenant::onlyTrashed()
            ->where('slug', $tenant_slug)
            ->firstOrFail();

        DB::connection("landlord")->beginTransaction();
        try {
            $tenant->domains()->delete();
            $tenant->forceDelete();
        } catch (\Exception $e) {
            DB::connection("landlord")->rollBack();
            abort(422, "Erro ao desfazer relacionamentos");
        }

        DB::connection("landlord")->commit();

        return response()->json();
    }

    protected function filterGuardedParameters(array $received_params): array {
        $guarded = $this->tenant->getGuarded();

        return collect($received_params)
            ->reject(fn ($value, $key) => in_array($key, $guarded) )
            ->toArray();
    }
}
