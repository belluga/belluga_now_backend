<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\RolesDeleteRequest;
use App\Http\Api\v1\Requests\RolesStoreRequest;
use App\Http\Api\v1\Requests\RolesUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class RolesAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        if (!$user->hasPermissionTo('role.view')) {
            abort(403, 'You do not have permission to view roles.');
        }

        $roles = Role::when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate(15);

        return response()->json($roles);
    }

    public function store(RolesStoreRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $account_slug = $request->route('account_slug');

        if (!$user->hasPermissionTo('account.role.create')) {
            abort(403, 'You do not have permission to create account roles.');
        }

        $account = Account::where('slug', $account_slug)
            ->firstOrFail();

        try {
            DB::beginTransaction();

            $role = $account->roles()->create(
                [
                    ...$request->validated(),
                    'account_id' => $account->id,
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

    public function show(string $account_slug, string $role_id): JsonResponse
    {

        $account = Account::where('slug', $account_slug)->firstOrFail();

        $role = $account->roles()->where('_id', new ObjectId($role_id))->firstOrFail();

        return response()->json([
            'data' => $role
        ]);
    }

    public function update(RolesUpdateRequest $request): JsonResponse
    {
        $account = Account::where('slug', $request->route("account_slug") )->firstOrFail();
        $role = $account->roles()->where('_id', new ObjectId($request->route("role_id")))->firstOrFail();

        if(empty($request->validated())){
            return response()->json([
                'message' => "Send at least one field to update.",
                'errors' => [
                    'empty' => ['Send at least one field to update.']
                ]
            ], 422);
        }

        $role->update($request->validated());

        return response()->json([
            'data' => $role
        ], 200);
    }

    public function destroy(RolesDeleteRequest $request): JsonResponse
    {
        $account = Account::where('slug', $request->route("account_slug") )->firstOrFail();
        $role = $account->roles()->where('_id', new ObjectId($request->route("role_id")))->firstOrFail();

        try{
            DB::beginTransaction();

            $role->users()->update(['role_id' => $request->validated()['role_id']]);
            $role->delete();

            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            return response()->json([
                    "message" => "Erro ao excluir role. Tente novamente mais tarde.",
                ],
                422
            );
        }

        return response()->json([]);
    }

    public function restore(string $account_slug, string $role_id): JsonResponse
    {
        $account = Account::where('slug', $account_slug)->firstOrFail();

        $role = $account->roles()
            ->onlyTrashed()
            ->where('_id', new ObjectId($role_id))
            ->firstOrFail();

        $role->restore();

        return response()->json([
            "data" => $role
        ]);
    }

    public function forceDestroy(string $account_slug, string $role_id): JsonResponse
    {
        $account = Account::where('slug', $account_slug)->firstOrFail();

        $role = $account->roles()
            ->onlyTrashed()
            ->where('_id', new ObjectId($role_id))
            ->firstOrFail();

        $role->forceDelete();

        return response()->json();
    }
}
