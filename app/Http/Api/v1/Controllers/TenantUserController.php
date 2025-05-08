<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TenantUserController extends Controller
{

    /**
     * Lista todos os usuários de um tenant
     */
    public function index(Request $request): JsonResponse
    {
        $users = TenantUser::
            when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate();

        return response()->json($users);
    }

    /**
     * Exibe um usuário específico
     */
    public function show(string $id): JsonResponse
    {
        $user = TenantUser::findOrFail($id);
        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário para o tenant atual
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:tenant.tenant_users,email',
            'password' => 'required|string|min:8',
            'role_id' => 'nullable|string|exists:tenant.roles,_id',
            'account_id' => 'nullable|string|exists:tenant.accounts,_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $user = new TenantUser([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role_id' => $request->input('role_id'),
            'account_id' => $request->input('account_id'),
            'active' => true,
        ]);

        $user->save();

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'data' => $user
        ], 201);
    }

    /**
     * Atualiza um usuário existente
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = TenantUser::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:tenant.tenant_users,email,' . $id . ',_id',
            'role_id' => 'nullable|string|exists:tenant.roles,_id',
            'account_id' => 'nullable|string|exists:tenant.accounts,_id',
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

        if ($request->has('role_id')) {
            $user->role_id = $request->input('role_id');
        }

        if ($request->has('account_id')) {
            $user->account_id = $request->input('account_id');
        }

        $user->save();

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user
        ]);
    }

    /**
     * Remove um usuário
     */
    public function destroy(string $user_id): JsonResponse
    {
        $user = TenantUser::findOrFail($user_id);

        // Impede que o usuário atual seja excluído
        if ($user->_id === Auth::id()) {
            return response()->json(['message' => 'Não é possível excluir o próprio usuário'], 422);
        }

        //remove relationships
        $user->delete();;

        return response()->json(['message' => 'Usuário removido com sucesso']);
    }

    public function restore($user_id): JsonResponse {
        $user = TenantUser::onlyTrashed()->findOrFail($user_id);
        $user->restore();

        return response()->json();
    }

    public function forceDestroy($user_id): JsonResponse {
        $user = TenantUser::onlyTrashed()->findOrFail($user_id);

        if ($user->_id === Auth::id()) {
            return response()->json(['message' => 'Não é possível excluir o próprio usuário'], 422);
        }

        $user->forceDelete();

        return response()->json();
    }

    /**
     * Atualiza a senha de um usuário
     */
    public function updatePassword(Request $request, string $id): JsonResponse
    {
        $user = TenantUser::findOrFail($id);

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
}
