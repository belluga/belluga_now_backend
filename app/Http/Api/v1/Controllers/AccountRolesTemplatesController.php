<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\AccountRolesDeleteRequest;
use App\Http\Api\v1\Requests\AccountRoleTemplatesStoreRequest;
use App\Http\Api\v1\Requests\AccountRolesUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountRolesTemplatesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = AccountRoleTemplate::when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate(15);

        return response()->json($roles);
    }

    public function store(AccountRoleTemplatesStoreRequest $request): JsonResponse
    {

        $account = Account::current();

        try {
            $role = $account->roleTemplates()->create($request->validated());

            return response()->json([
                'data' => $role
            ], 201);

        } catch (BulkWriteException $e) {
            DB::rollBack();

            if (str_contains($e->getMessage(), 'E11000')) {
                abort(422, 'Role already exists for this account.');
            }

            abort(422, 'Something went wrong when trying to create the role.');
        }
    }

    public function show(string $account_slug, string $role_id): JsonResponse
    {

        $account = Account::current();

        $role = $account->roleTemplates()->where('_id', new ObjectId($role_id))->firstOrFail();

        return response()->json([
            'data' => $role
        ]);
    }

    public function update(AccountRolesUpdateRequest $request): JsonResponse
    {
        $account = Account::current();
        $role = $account->roleTemplates()->where('_id', new ObjectId($request->route("role_id")))->firstOrFail();

        if(empty($request->validated())){
            return response()->json([
                'message' => "Send at least one field to update.",
                'errors' => [
                    'empty' => [
                        'Send at least one field to update.'
                    ]
                ]
            ], 422);
        }

        $role->update($request->validated());

        return response()->json([
            'data' => $role
        ], 200);
    }

    public function destroy(AccountRolesDeleteRequest $request): JsonResponse
    {
        if($request->route("role_id") == $request->validated()['role_id']){
            return response()->json([
                "message" => "Role ID background should be different from the role ID to be deleted.",
                "errors" => [
                    "role_id" => [
                        "Role ID background should be different from the role ID to be deleted."
                    ],
                ]
            ],
                422
            );
        }

        $account = Account::current();

        $role_to_delete = $account->roleTemplates()->where('_id', new ObjectId($request->route("role_id")))->firstOrFail();

        $role_background = $account->roleTemplates()->where('_id', new ObjectId($request->validated()['role_id']))->firstOrFail();

        try{
            DB::beginTransaction();

            $account_users = AccountUser::where("account_roles.slug", $role_to_delete->slug)
                ->where("account_roles.account_id", $account->id)
                ->get();

            foreach ($account_users as $account_user) {
                $account_user->accountRoles()
                    ->where("account_roles.role_slug", $role_to_delete->slug)
                    ->update([
                        'slug' => $role_background->slug,
                        'permissions' => $role_background->permissions,
                    ]);
            }

            $role_to_delete->delete();

            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            return response()->json([
                    "message" => "Erro ao excluir role. Tente novamente mais tarde.",
                    "errors" => [
                        "database" => [
                            "Erro ao excluir role. Tente novamente mais tarde."
                        ]
                    ]
                ],
                422
            );
        }

        return response()->json([]);
    }

    public function restore(string $account_slug, string $role_id): JsonResponse
    {
        $account = Account::current();

        $role = $account->roleTemplates()
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
        $account = Account::current();

        $role = $account->roleTemplates()
            ->onlyTrashed()
            ->where('_id', new ObjectId($role_id))
            ->firstOrFail();

        $role->forceDelete();

        return response()->json();
    }
}
