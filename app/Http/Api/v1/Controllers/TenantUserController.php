<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Controllers\Traits\HasAccountInSlug;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\TenantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Stancl\Tenancy\Contracts\TenantManager;

class TenantUserController extends Controller
{
    use HasAccountInSlug;

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
     * Lista todos os usuários de uma conta específica
     */
    public function accountUsers(string $account_slug): JsonResponse
    {
        $this->extractAccountFromSlug();

        $users = TenantUser::where('account_id', $this->account->_id)->paginate(20);

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
     * Cria um novo usuário para uma conta específica
     */
    public function storeForAccount(Request $request, string $account_slug): JsonResponse
    {
        $this->extractAccountFromSlug();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:tenant.tenant_users,email',
            'password' => 'required|string|min:8',
            'role_id' => 'nullable|string|exists:tenant.roles,_id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $user = new TenantUser([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role_id' => $request->input('role_id'),
            'account_id' => $this->account->_id,
            'active' => true,
        ]);

        $user->save();

        return response()->json([
            'message' => 'Usuário criado com sucesso para a conta ' . $this->account->name,
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
     * Exibe o perfil do usuário atual
     */
    public function profile(): JsonResponse
    {
        $user = Auth::user();
        return response()->json(['data' => $user]);
    }

    /**
     * Atualiza o perfil do usuário atual
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:tenant.tenant_users,email,' . $user->_id . ',_id',
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

    /**
     * Ativa ou desativa um usuário
     */
    public function toggleActive(string $id): JsonResponse
    {
        $user = TenantUser::findOrFail($id);

        // Impede que o usuário atual seja desativado
        if ($user->_id === Auth::id()) {
            return response()->json(['message' => 'Não é possível desativar o próprio usuário'], 422);
        }

        $user->active = !$user->active;
        $user->save();

        $status = $user->active ? 'ativado' : 'desativado';

        return response()->json(['message' => "Usuário {$status} com sucesso"]);
    }

    /**
     * Lista todos os usuários de um tenant específico (API do landlord)
     */
    public function tenantUsers(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        // Configura a conexão para o tenant atual
        tenancy()->initialize($tenant);

        $users = TenantUser::paginate(20);

        return response()->json($users);
    }

    /**
     * Cria um novo usuário para um tenant específico (API do landlord)
     */
    public function storeForTenant(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8',
            'role_id' => 'nullable|string',
            'account_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        // Configura a conexão para o tenant atual
        tenancy()->initialize($tenant);

        // Verifica se o email já existe
        if (TenantUser::where('email', $request->input('email'))->exists()) {
            return response()->json(['message' => 'O email já está em uso neste tenant'], 422);
        }

        // Verifica se a conta existe
        if ($request->has('account_id')) {
            $accountExists = Account::where('_id', $request->input('account_id'))->exists();
            if (!$accountExists) {
                return response()->json(['message' => 'A conta especificada não existe neste tenant'], 422);
            }
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
            'message' => 'Usuário criado com sucesso no tenant ' . $tenant->name,
            'data' => $user
        ], 201);
    }
}
