<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\AccountUserQueryService;
use App\Application\Accounts\AccountUserService;
use App\Http\Api\v1\Requests\AccountUserCreateRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AccountUserController extends Controller
{
    public function __construct(
        private readonly AccountUserService $accountUserService,
        private readonly AccountUserQueryService $accountUserQueryService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    /**
     * Lista todos os usuários de um tenant
     */
    public function index(Request $request): JsonResponse
    {
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $perPage = (int) $request->get('per_page', 15);

        $paginator = $this->accountUserQueryService->paginate(
            $account,
            $request->query(),
            $request->boolean('archived'),
            $perPage > 0 ? $perPage : 15
        );

        return response()->json($paginator);
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

        $actor = $request->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_user_created',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'target_user_id' => (string) $user->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

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

        $validated = $request->validated();
        $updated = $this->accountUserService->update($user, $validated);

        $actor = $request->user();
        $account = Account::current();
        if ($actor && $account) {
            $this->telemetry->emit(
                event: 'account_user_updated',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'target_user_id' => (string) $user->_id,
                    'changed_fields' => array_keys($validated),
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

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

        $actor = $request->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_user_deleted',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'target_user_id' => (string) $user->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json(['message' => 'Usuário removido da conta com sucesso']);
    }

    private function getFirstUserByRouteOrFail(): AccountUser
    {
        $userId = (string) request()->route('user_id');
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        return $this->accountUserQueryService->findByIdForAccountOrFail($account, $userId);
    }
}
