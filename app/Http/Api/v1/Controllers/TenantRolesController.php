<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\TenantRoleDestroyRequest;
use App\Http\Api\v1\Requests\TenantRoleStoreRequest;
use App\Http\Api\v1\Requests\TenantRoleUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Illuminate\Http\Request;

class TenantRolesController extends Controller
{

    /**
     * Display a listing of the tenant roles.
     */
    public function index(Request $request): JsonResponse
    {
        $roles = TenantRoleTemplate::when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->where("tenant_id", Tenant::current()->id)
            ->paginate(15);

        return response()->json($roles);
    }

    /**
     * Store a newly created tenant role.
     */
    public function store(TenantRoleStoreRequest $request): JsonResponse
    {
        try {
            $role = TenantRoleTemplate::create([
                ...$request->validated(),
                'tenant_id' => Tenant::current()->id,
            ]);

            return response()->json([
                'data' => $role
            ], 201);

        } catch (BulkWriteException $e) {

            if (str_contains($e->getMessage(), 'E11000')) {
                abort(422, 'Role already exists.');
            }

            abort(422, 'Something went wrong when trying to create the role.');
        }
    }

    /**
     * Display the specified role.
     */
    public function show(string $role_id): JsonResponse
    {
        $role = TenantRoleTemplate::where("_id", new ObjectId($role_id))
            ->where("tenant_id", Tenant::current()->id)
            ->firstOrFail();

        return response()->json([
            'data' => $role
        ]);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(TenantRoleUpdateRequest $request, string $role_id): JsonResponse
    {

        $role = TenantRoleTemplate::where("_id", new ObjectId($role_id))
            ->where("tenant_id", Tenant::current()->id)
            ->firstOrFail();

        $role->update($request->validated());

        return response()->json([
            'data' => $role
        ]);
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(TenantRoleDestroyRequest $request, string $role_id): JsonResponse
    {

        $role = TenantRoleTemplate::where("_id", new ObjectId($role_id))
            ->where("tenant_id", Tenant::current()->id)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            LandlordUser::where("role_id", $role->id)
                ->update(['role_id' => $request->validated()['role_id']]);;

            $role->delete();
            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            abort(422, "Erro ao excluir role. Tente novamente mais tarde.");
        }

        return response()->json([], 200);
    }

    public function forceDestroy(string $role_id): JsonResponse {


        $role = TenantRoleTemplate::onlyTrashed()
            ->where("_id", new ObjectId($role_id))
            ->where("tenant_id", Tenant::current()->id)
            ->firstOrFail();

        DB::beginTransaction();
        try{
            $role->forceDelete();
        }catch (\Exception $e){
            DB::rollBack();
            return response()->json(["errors" => ["role" => ["Error deleting relationships."]]]);
        }
        DB::commit();


        return response()->json();
    }

    /**
     * Restore a soft-deleted role.
     */
    public function restore(string $role_id): JsonResponse
    {

        $role = TenantRoleTemplate::onlyTrashed()
            ->where("_id", new ObjectId($role_id))
            ->where("tenant_id", Tenant::current()->id)
            ->firstOrFail();

        $role->restore();

        return response()->json([], 200);
    }

    private function getAccessIds(): array {
        $user = auth()->guard('sanctum')->user();
        return $user->getAccessToIds();
    }

    private function getAccessObjectIds(): array {
        return array_map(fn($id) => new \MongoDB\BSON\ObjectId($id), $this->getAccessIds());
    }
}
