<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\LandlordRoleDestroyRequest;
use App\Http\Api\v1\Requests\LandlordRoleStoreRequest;
use App\Http\Api\v1\Requests\LandlordRoleUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;
use Illuminate\Http\Request;

class LandlordRolesController extends Controller
{
    /**
     * Display a listing of the roles.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        $roles = LandlordRole::when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate(15);

        return response()->json($roles);
    }

    /**
     * Display a listing of the tenant roles.
     */
    public function tenantRoles(string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        $tenant = Tenant::where('slug', $tenant_slug)->firstOrFail();
        $roles = LandlordRole::tenantRoles($tenant->id)->paginate(15);

        return response()->json($roles);
    }

    /**
     * Store a newly created system role.
     */
    public function storeSystemRole(LandlordRoleStoreRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        try {
            DB::beginTransaction();

            $role = LandlordRole::create([
                ...$request->validated(),
            ]);

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

    /**
     * Store a newly created tenant role.
     */
    public function storeTenantRole(LandlordRoleStoreRequest $request, string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        $tenant = Tenant::where('slug', $tenant_slug)->firstOrFail();

        try {
            DB::beginTransaction();

            $role = LandlordRole::create([
                ...$request->validated(),
                'is_system_role' => false,
                'tenant_id' => $tenant->id,
                'creator_id' => $user->id
            ]);

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

    /**
     * Display the specified role.
     */
    public function show(string $role_id): JsonResponse
    {

        $role = LandlordRole::findOrFail($role_id);

        return response()->json([
            'data' => $role
        ]);
    }

    /**
     * Update the specified role in storage.
     */
    public function update(LandlordRoleUpdateRequest $request, string $role_id): JsonResponse
    {

        $role = LandlordRole::findOrFail($role_id);
        $role->update($request->validated());

        return response()->json([
            'data' => $role
        ]);
    }

    /**
     * Remove the specified role from storage.
     */
    public function destroy(LandlordRoleDestroyRequest $request): JsonResponse
    {

        $role = LandlordRole::findOrFail($request->route("role_id"));

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

    public function forceDestroy($user_id): JsonResponse {
        $role = LandlordRole::onlyTrashed()->findOrFail($user_id);

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

        $role = LandlordRole::onlyTrashed()
            ->where('_id', $role_id)
            ->firstOrFail();

        $role->restore();

        return response()->json([], 200);
    }

    /**
     * Assign a role to a landlord user.
     */
    public function assignRoleToUser(string $role_id, string $user_id): JsonResponse
    {

        $role = LandlordRole::findOrFail($role_id);
        $user = LandlordUser::findOrFail($user_id);

        $user->role()->associate($role);
        $user->save();

        return response()->json([], 200);
    }

    /**
     * Remove a role from a landlord user.
     */
    public function removeRoleFromUser(string $role_id, string $user_id): JsonResponse
    {

        $role = LandlordRole::findOrFail($role_id);
        $user = LandlordUser::findOrFail($user_id);

        $user->roles()->detach($role->id);

        return response()->json([], 200);
    }
}
