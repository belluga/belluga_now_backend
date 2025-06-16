<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\AccountUserEmailsAddRequest;
use App\Http\Api\v1\Requests\AccountUserEmailsRemoveRequest;
use App\Http\Api\v1\Requests\AccountUserCreateRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;

class AccountUserController extends Controller
{

    /**
     * Lista todos os usuários de um tenant
     */
    public function index(Request $request): JsonResponse
    {
        $users = AccountUser::
            when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate();

        return response()->json($users);
    }

    /**
     * Exibe um usuário específico
     */
    public function show(Request $request): JsonResponse
    {
        $user = AccountUser::where('_id', new ObjectId($request->route("user_id")))->firstOrFail();
        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário para o tenant atual
     */
    public function store(AccountUserCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = AccountUser::create($request->validated());
            $role = AccountRoleTemplate::where('_id', new ObjectId($request->role_id))->firstOrFail();

            $user->tenantRoles()->create([
                ...$role->attributesToArray(),
                'account_id' =>Account::current()->id,
            ]);

            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            print($e->getMessage());
            return response()->json([
                'message' => 'An error occurred while trying to create the user. Please try again later.',
                'errors' => [
                    'database' => ['An error occurred while trying to create the user. Please try again later.']
                ]
            ], 422);
        }

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'data' => $user
        ], 201);
    }

    /**
     * Atualiza um usuário existente
     */
    public function update(UserUpdateRequest $request): JsonResponse
    {
        $user = AccountUser::findOrFail($request->route("user_id"));

        $user->update($request->validated());

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user
        ]);
    }

    /**
     * Remove um usuário
     */
    public function destroy(Request $request): JsonResponse
    {
        $user_id = $request->route("user_id");

        $user = AccountUser::where("_id", new ObjectId($user_id))->firstOrFail();;

        if ($user->_id === Auth::id()) {
            return response()->json(
                [
                    'message' => 'Não é possível excluir o próprio usuário',
                    'errors' => [
                        "user_id" => [
                            'Não é possível excluir o próprio usuário'
                        ]
                    ]
                ],
                422);
        }

        //remove relationships
        $user->delete();

        return response()->json(['message' => 'Usuário removido com sucesso']);
    }

    public function restore(Request $request): JsonResponse {

        $user_id = $request->route("user_id");

        $user = AccountUser::onlyTrashed()
            ->where("_id", new ObjectId($user_id))
            ->firstOrFail();

        $user->restore();

        return response()->json(
            [
                "data" => UserResource::make($user)
            ]
        );
    }

    public function forceDestroy(Request $request): JsonResponse {

        $user_id = $request->route("user_id");

        $user = AccountUser::onlyTrashed()
            ->where("_id", new ObjectId($user_id))
            ->firstOrFail();

        if ($user->_id === Auth::id()) {
            return response()->json([
                'message' => 'Não é possível excluir o próprio usuário',
                'errors' => [
                    "user_id" => [
                        "Não é possível excluir o próprio usuário"
                    ]
                ]
            ], 422);
        }

        $user->forceDelete();

        return response()->json();
    }

    /**
     * Atualiza a senha de um usuário
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user_id = $request->route("user_id");

        $user = AccountUser::onlyTrashed()
            ->where("_id", new ObjectId($user_id))
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    public function addEmails(AccountUserEmailsAddRequest $request): JsonResponse
    {
        $user_id = $request->route("user_id");

        $user = AccountUser::where("_id", new ObjectId($user_id))->firstOrFail();

        $new_emails = $request->input('emails');

        try{
            $user->push('emails', $new_emails);
        }catch (\Exception $e){
            if (str_contains($e->getMessage(), 'E11000')) {
                return response()->json([
                    'message' => 'An email already exists.',
                    'errors' => ['emails' => ["One of the emails given already exists.."]]
                ], 422);
            }

            return response()->json([
                    "message" => "Erro ao adicionar emails. Tente novamente mais tarde.",
                    'errors' => [
                        'emails' => [
                            "Erro ao adicionar emails. Tente novamente mais tarde"
                        ]
                    ]
                ],
               422
            );
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user
        ]);
    }

    public function removeEmails(AccountUserEmailsRemoveRequest $request): JsonResponse
    {
        $user_id = $request->route("user_id");

        $user = AccountUser::where("_id", new ObjectId($user_id))->firstOrFail();

        $remove_emails = $request->input('emails');

        try{
            $user->pull('emails', $remove_emails);
        }catch (\Exception $e){
            return response()->json([
                "message" => "Erro ao adicionar emails. Tente novamente mais tarde.",
                "errors" => [
                    "emails" => [
                        "Erro ao adicionar emails. Tente novamente mais tarde."
                    ]
                ]
            ],
                422
            );
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user
        ]);
    }
}
