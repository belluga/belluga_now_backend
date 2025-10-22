<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Domain\FoundationControlPlane\Identity\Exceptions\IdentityAlreadyExistsException;
use App\Domain\FoundationControlPlane\Identity\PasswordIdentityRegistrar;
use App\Http\Api\v1\Requests\AccountUserCreateRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;
use MongoDB\BSON\ObjectId;
use Illuminate\Validation\ValidationException;

class AccountUserController extends Controller
{

    /**
     * Lista todos os usuários de um tenant
     */
    public function index(Request $request): JsonResponse
    {
        $users = AccountUser::
            when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->where("account_roles.account_id", Account::current()->id)
            ->paginate();

        return response()->json($users);
    }

    /**
     * Exibe um usuário específico
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->getFirstUserByRouteOrFail();
        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário para o tenant atual
     */
    public function store(AccountUserCreateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = $this->findOrCreateUser($request->validated());

            if(!$user->isActive()){
                $user->restore();
            }

            if(!$user->haveAccessTo(Account::current())){
                $role_template = AccountRoleTemplate::where('_id', new ObjectId($request->role_id))->firstOrFail();

                $user->accountRoles()->create([
                    ...$role_template->attributesToArray(),
                    'account_id' =>Account::current()->id,
                ]);
            }
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
        if(empty($request->validated())){
            throw ValidationException::withMessages([
                'empty' => "Nenhum dado recebido para atualizar."
            ]);
        }

        $user = $this->getFirstUserByRouteOrFail();
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
        $user = $this->getFirstUserByRouteOrFail();

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

        $user->accountRoles()
            ->where("account_id", Account::current()->id)
            ->first()
            ->delete();

        $user_have_no_account_access = count($user->getAccessToIds()) == 0;

        if($user_have_no_account_access){
            $user->delete();
        }

        return response()->json(['message' => 'Usuário removido da conta com sucesso']);
    }

    private function getFirstUserByRouteOrFail(): AccountUser {
        $user_id = request()->route("user_id");

        return AccountUser::where("_id", new ObjectId($user_id))
            ->where("account_roles.account_id", Account::current()->id)
            ->firstOrFail();
    }

    private function findOrCreateUser(array $data): AccountUser {

        $user = AccountUser::withTrashed()
            ->whereRaw([
                'emails' => ['$in' => $data['emails']]
            ])->first();

        if (! $user) {
            $registrar = app(PasswordIdentityRegistrar::class);

            try {
                return $registrar->register(Arr::except($data, ['role_id']));
            } catch (IdentityAlreadyExistsException $exception) {
                abort(422, 'An identity with the provided contact points already exists.');
            }
        }

        if ($user->trashed()) {
            $user->restore();
        }

        if (! empty($data['emails'])) {
            foreach ($data['emails'] as $email) {
                $user->ensureEmail($email);
            }
        }

        if (! empty($data['password'])) {
            $passwordHash = Hash::make($data['password']);
            $user->password = $passwordHash;
            foreach ($data['emails'] as $email) {
                $user->syncCredential('password', $email, $passwordHash);
            }
        }

        return $user;
    }
}
