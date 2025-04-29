<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class LandlordUserController extends Controller
{
    /**
     * Lista todos os usuários do landlord
     */
    public function index(): JsonResponse
    {
        $users = LandlordUser::paginate(20);
        return response()->json($users);
    }

    /**
     * Exibe um usuário específico do landlord
     */
    public function show(string $id): JsonResponse
    {
        $user = LandlordUser::findOrFail($id);
        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário do landlord
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:landlord_users,email',
            'password' => 'required|string|min:8',
            'role' => 'nullable|string|in:admin,manager,viewer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $user = new LandlordUser([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role' => $request->input('role', 'viewer'),
            'active' => true,
        ]);

        $user->save();

        return response()->json([
            'message' => 'Usuário do landlord criado com sucesso',
            'data' => $user
        ], 201);
    }

    /**
     * Atualiza um usuário existente do landlord
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = LandlordUser::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:landlord_users,email,' . $id,
            'role' => 'nullable|string|in:admin,manager,viewer',
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

        if ($request->has('role')) {
            $user->role = $request->input('role');
        }

        $user->save();

        return response()->json([
            'message' => 'Usuário do landlord atualizado com sucesso',
            'data' => $user
        ]);
    }

    /**
     * Remove um usuário do landlord
     */
    public function destroy(string $id): JsonResponse
    {
        $user = LandlordUser::findOrFail($id);

        // Impede que o usuário atual seja excluído
        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'Não é possível excluir o próprio usuário'], 422);
        }

        $user->delete();

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
}
