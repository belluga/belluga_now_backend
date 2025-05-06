<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\LandlordUserCreateRequest;
use App\Http\Api\v1\Requests\LandlordUserUpdateRequest;
use App\Http\Api\v1\Requests\TenantLandlordUserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LandlordUserController extends Controller
{
    protected ?LandlordUser $user;

    /**
     * Lista todos os usuários do landlord
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return LandlordUser::with('tenants')
            ->when($request->has('archive'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate(20);
//        return response()->json($users);
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

        $user = LandlordUser::create($request->validated());

        return response()->json([
            'message' => 'Usuário do landlord criado com sucesso',
            'data' => $user
        ], 201);
    }

    /**
     * Atualiza um usuário existente do landlord
     */
    public function update(LandlordUserUpdateRequest $request, string $user_id): JsonResponse
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

    public function tenantUserManage(TenantLandlordUserAttachRequest $request, $user_id): JsonResponse {
        $user = LandlordUser::find($user_id);

        if(!$user){
            return response()->json([
                "message" => "User not found.",
                "errors" => ["user_id" => "User not found."]
            ], 422);
        }

        $tenant = Tenant::findBySlug(request()->tenant_slug);

        if(!$tenant){
            return response()->json([
                "message" => "Tenant not found.",
                "errors" => ["tenant_slug" => "Tenant not found."]
            ], 422);
        }

        $method = strtolower($request->method());

        switch( $method){
            case 'post':
                $tenant->users()->attach($user);
                break;
            case 'delete':
                $tenant->users()->detach($user);
                break;
            default:
                return response()->json([
                    "message" => "Not found an action for this method.",
                    "errors" => ["method" => "Not found an action for this method."]
                ], 422);
        }

        return response()->json();
    }

    public function restore($user_id): JsonResponse {
        $user = LandlordUser::onlyTrashed()->findOrFail($user_id);
        $user->restore();

        return response()->json();
    }

    public function forceDestroy($user_id): JsonResponse {
        $user = LandlordUser::onlyTrashed()->findOrFail($user_id);
        $user->forceDelete();

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

        // Remove all relationships with modules
        DB::beginTransaction();
        try{
//            $user->modules()->detach();
            $user->tenants()->detach();
            $user->delete();
        }catch(\Exception $e){
            DB::rollBack();
            throw $e;
        }

        DB::commit();

        return response()->json(['message' => 'Usuário do landlord removido com sucesso']);
    }

    /**
     * Exibe o perfil do usuário atual do landlord
     */
    public function profile(): JsonResponse
    {
        $user = Auth::user();
        return response()->json(['data' => $user]);
    }

    /**
     * Atualiza o perfil do usuário atual do landlord
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:landlord_users,email,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        if ($request->has('name')) {
            $user->name = $request->input('name');
        }

        if ($request->has('email')) {
            $user->email = $request->input('email');
        }

        $user->save();

        return response()->json([
            'message' => 'Perfil atualizado com sucesso',
            'data' => $user
        ]);
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

    /**
     * Ativa ou desativa um usuário do landlord
     */
    public function toggleActive(string $id): JsonResponse
    {
        $user = LandlordUser::findOrFail($id);

        // Impede que o usuário atual seja desativado
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Não é possível desativar o próprio usuário'], 422);
        }

        $user->active = !$user->active;
        $user->save();

        $status = $user->active ? 'ativado' : 'desativado';

        return response()->json(['message' => "Usuário do landlord {$status} com sucesso"]);
    }

    protected function filterGuardedParameters(array $received_params): array {
        $guarded = $this->user->getGuarded();

        return collect($received_params)
            ->reject(fn ($value, $key) => in_array($key, $guarded) )
            ->toArray();
    }
}
