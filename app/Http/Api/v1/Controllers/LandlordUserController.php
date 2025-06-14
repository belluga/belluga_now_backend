<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\LandlordUserCreateRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Api\v1\Requests\TenantLandlordUserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;

class LandlordUserController extends Controller
{
    protected ?LandlordUser $user;

    /**
     * Lista todos os usuários do landlord
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return LandlordUser::when(
            $request->has('archived'),
            fn ($query, $name) => $query->onlyTrashed()
        )
        ->paginate();
    }

    /**
     * Exibe um usuário específico do landlord
     */
    public function show(string $user_id): JsonResponse
    {
        $user = LandlordUser::findOrFail($user_id)
            ?? abort(404);


        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário do landlord
     */
    public function store(LandlordUserCreateRequest $request): JsonResponse
    {

        DB::beginTransaction();
        try {
            $user = LandlordUser::create($request->validated());
            $role = Role::where('_id', new ObjectId($request->role_id))->firstOrFail();
            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            return response()->json([
                'message' => 'An error occurred while trying to create the user. Please try again later.',
                'data' => $user
            ], 422);
        }


        $role->users()->save($user);

        return response()->json([
            'message' => 'Usuário do landlord criado com sucesso',
            'data' => $user
        ], 201);
    }

    /**
     * Atualiza um usuário existente do landlord
     */
    public function update(UserUpdateRequest $request, string $user_id): JsonResponse
    {
        $this->user = LandlordUser::findOrFail($user_id)
            ?? abort(404);

        $params_to_update = $this->filterGuardedParameters($request->validated());
        $this->user->update($params_to_update);

        return response()->json([
            'message' => 'Usuário do landlord atualizado com sucesso',
            'data' => $this->user
        ]);
    }

    public function tenantUserManage(TenantLandlordUserAttachRequest $request, $tenant_slug): JsonResponse {

        $tenant = Tenant::findBySlug($tenant_slug) ?? abort(422, "Tenant not found");

        $users = LandlordUser::whereIn('_id', request()->user_ids)->get();

        if(count($users) < 1){
            abort(422, "No users found");
        }

        $method = strtolower($request->method());

        DB::beginTransaction();
        try {
            switch( $method){
                case 'post':
                    $users->each(fn($user) => $user->haveAccessTo()->attach($tenant));
                    break;
                case 'delete':
                    $users->each(fn($user) => $user->haveAccessTo()->dettach($tenant));
                    break;
                default:
                    abort(422, "Not found an action for this method.");
            }
        }catch (\Exception $e){
            DB::rollBack();
            print_r($e->getMessage());
            abort(422, "An error occurred while trying to manage the users for this tenant. Please try again later.");
        }

        DB::commit();

        return response()->json();
    }

    public function restore($user_id): JsonResponse {
        $user = LandlordUser::onlyTrashed()->findOrFail($user_id);
        $user->restore();

        return response()->json(["data" => $user]);
    }

    public function forceDestroy($user_id): JsonResponse {
        $user = LandlordUser::onlyTrashed()->findOrFail($user_id);

        try{
            $user->forceDelete();
        }catch (\Exception $e){
            return response()->json(["errors" => ["relationships" => ["Error deleting relationships."]]]);
        }

        return response()->json();
    }

    /**
     * Remove um usuário do landlord
     */
    public function destroy(string $user_id): JsonResponse
    {
        $user = LandlordUser::findOrFail($user_id);

        if ((string)$user->_id === Auth::id()) {
            return response()->json(
                [
                    "errors" => [
                        "user" => ['Não é possível excluir o próprio usuário']
                    ]
                ],
                403
            );
        }

       $user->delete();

        return response()->json(['message' => 'Usuário do landlord removido com sucesso']);
    }

    /**
     * Atualiza a senha de um usuário do landlord
     */
    public function updatePassword(Request $request, string $id): JsonResponse
    {
        $user = LandlordUser::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    protected function filterGuardedParameters(array $received_params): array {
        $guarded = $this->user->getGuarded();

        return collect($received_params)
            ->reject(fn ($value, $key) => in_array($key, $guarded) )
            ->toArray();
    }
}
