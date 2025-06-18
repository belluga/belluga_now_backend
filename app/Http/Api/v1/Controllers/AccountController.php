<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\AccountStoreRequest;
use App\Http\Api\v1\Requests\AccountUpdateRequest;
use App\Http\Api\v1\Requests\AccountUserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        return Account::whereRaw(["_id" => ['$in' => $this->getAccessObjectIds()]] )
            ->when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate(15);
    }

    public function store(AccountStoreRequest $request): JsonResponse
    {
        try {

            DB::beginTransaction();

            $account = Account::create($request->validated());

            $role = $account->roleTemplates()->create([
                'name' => 'Admin',
                'description' => 'Administrador',
                'permissions' => [
                    '*'
                ]
            ]);

            DB::commit();

            return response()->json([
                'data' => [
                    'account' => $account,
                    'role' => $role,
                ]
            ], 201);

        } catch (BulkWriteException $e) {
            DB::rollBack();
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

    public function show(string $account_slug): JsonResponse
    {

        $account = Account::where("slug", $account_slug)->firstOrFail();

        return response()->json([
            'data' => $account
        ]);
    }

    public function update(AccountUpdateRequest $request, string $account_slug): JsonResponse
    {

        $account = Account::where("slug", $account_slug)->firstOrFail();
        $account->update($request->validated());

        return response()->json([
            'data' => $account
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {

        $account = Account::where('slug', $request->route('account_slug'))->firstOrFail();

        try {
            DB::beginTransaction();
            $account->roles()->delete();
            $account->delete();
            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            return response()->json([
                "message" => "Erro ao excluir conta. Tente novamente mais tarde.",
            ], 422);
        }

        return response()->json([], 200);
    }

    public function restore(string $account_slug): JsonResponse
    {
        $account = Account::onlyTrashed()->where('slug', $account_slug)->first();
        $account->restore();

        return response()->json([]);
    }

    public function forceDestroy(string $account_slug): JsonResponse
    {
        $account = Account::onlyTrashed()
            ->where('slug', $account_slug)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $account->roles()->forceDelete();
            $account->forceDelete();
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

    public function accountUserManage(AccountUserAttachRequest $request): JsonResponse {

        $account = Account::current();

        $user = AccountUser::where('_id', request()->user_id)->firstOrFail();

        $role = $account->roleTemplates()->where('_id', new ObjectId(request()->role_id))->firstOrFail();

        $method = strtolower($request->method());

        try {
            switch( $method){
                case 'post':
                    $user->tenantRoles()->create([
                        ...$role->attributesToArray(),
                        "account_id" => $account->id
                    ]);
                    break;
                case 'delete':
                    $role_to_delete = $user->tenantRoles()
                        ->where('slug', $role->slug)
                        ->where('account_id', $account->id)
                        ->first();

                    if ($role_to_delete) {
                        $role_to_delete->delete();
                        $user->save();
                    }
                    break;
                default:
                    abort(422, "Not found an action for this method.");
            }
        }catch (\Exception $e){
            abort(422, "An error occurred while trying to manage the users for this tenant. Please try again later.");
        }

        return response()->json();
    }

    private function getAccessObjectIds(): array {
        $user = auth()->guard('sanctum')->user();
        return array_map(fn($id) => new \MongoDB\BSON\ObjectId($id), $user->getAccessToIds());
    }

//    protected function filterGuardedParameters(array $received_params): array {
//        $guarded = $this->tenant->getGuarded();
//
//        return collect($received_params)
//            ->reject(fn ($value, $key) => in_array($key, $guarded) )
//            ->toArray();
//    }
}
