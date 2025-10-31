<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Accounts\AccountUserService;
use App\Http\Api\v1\Requests\AccountUserCreateRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MongoDB\BSON\ObjectId;
use Illuminate\Validation\ValidationException;

class AccountUserController extends Controller
{
    public function __construct(
        private readonly AccountUserService $accountUserService
    ) {
    }

    /**
     * Lista todos os usuários de um tenant
     */
    public function index(Request $request): JsonResponse
    {
        $users = AccountUser::
            when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->where('account_roles.account_id', Account::current()->id)
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
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $user = $this->accountUserService->create(
            $account,
            $request->validated(),
            $request->string('role_id')->toString()
        );

        return response()->json([
            'message' => 'Usuário criado com sucesso',
            'data' => $user,
        ], 201);
    }

    /**
     * Atualiza um usuário existente
     */
    public function update(UserUpdateRequest $request): JsonResponse
    {
        if (empty($request->validated())) {
            throw ValidationException::withMessages([
                'empty' => 'Nenhum dado recebido para atualizar.'
            ]);
        }

        $user = $this->getFirstUserByRouteOrFail();

        $updated = $this->accountUserService->update($user, $request->validated());

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $updated,
        ]);
    }

    /**
     * Remove um usuário
     */
    public function destroy(Request $request): JsonResponse
    {
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $user = $this->getFirstUserByRouteOrFail();

        if ($user->_id === Auth::id()) {
            return response()->json(
                [
                    'message' => 'Não é possível excluir o próprio usuário',
                    'errors' => [
                        'user_id' => [
                            'Não é possível excluir o próprio usuário',
                        ],
                    ],
                ],
                422
            );
        }

        $this->accountUserService->remove($account, $user);

        return response()->json(['message' => 'Usuário removido da conta com sucesso']);
    }

    private function getFirstUserByRouteOrFail(): AccountUser
    {
        $userId = request()->route('user_id');

        return AccountUser::where('_id', new ObjectId($userId))
            ->where('account_roles.account_id', Account::current()->id)
            ->firstOrFail();
    }
}
