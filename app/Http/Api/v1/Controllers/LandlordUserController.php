<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\LandlordUsers\LandlordUserManagementService;
use App\Application\LandlordUsers\LandlordUserQueryService;
use App\Application\LandlordUsers\TenantUserRoleManager;
use App\Application\Telemetry\TelemetryEmitter;
use App\Http\Api\v1\Requests\LandlordUserCreateRequest;
use App\Http\Api\v1\Requests\TenantLandlordUserAttachRequest;
use App\Http\Api\v1\Requests\UserUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LandlordUserController extends Controller
{
    public function __construct(
        private readonly LandlordUserManagementService $landlordUserService,
        private readonly LandlordUserQueryService $landlordUserQueryService,
        private readonly TenantUserRoleManager $tenantUserRoleManager,
        private readonly TelemetryEmitter $telemetry
    ) {}

    /**
     * Lista todos os usuários do landlord
     */
    public function index(Request $request): LengthAwarePaginator
    {
        return $this->landlordUserQueryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            (int) $request->get('per_page', 15)
        );
    }

    /**
     * Exibe um usuário específico do landlord
     */
    public function show(string $user_id): JsonResponse
    {
        $user = $this->landlordUserService->find($user_id);

        return response()->json(['data' => $user]);
    }

    /**
     * Cria um novo usuário do landlord
     */
    public function store(LandlordUserCreateRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $user = $this->landlordUserService->create(
            $payload,
            $payload['role_id'],
            Auth::id()
        );

        return response()->json([
            'message' => 'Usuário do landlord criado com sucesso',
            'data' => $user,
        ], 201);
    }

    /**
     * Atualiza um usuário existente do landlord
     */
    public function update(UserUpdateRequest $request, string $user_id): JsonResponse
    {
        $user = $this->landlordUserService->find($user_id);

        $updated = $this->landlordUserService->update($user, $request->validated());

        return response()->json([
            'message' => 'Usuário do landlord atualizado com sucesso',
            'data' => $updated,
        ]);
    }

    public function restore($user_id): JsonResponse
    {
        $user = $this->landlordUserService->restore($user_id);

        return response()->json(['data' => $user]);
    }

    public function forceDestroy($user_id): JsonResponse
    {
        try {
            $this->landlordUserService->forceDelete($user_id);
        } catch (\Throwable $e) {
            return response()->json(['errors' => ['relationships' => ['Error deleting relationships.']]]);
        }

        return response()->json();
    }

    /**
     * Remove um usuário do landlord
     */
    public function destroy(string $user_id): JsonResponse
    {
        $user = $this->landlordUserService->find($user_id);
        /** @var \App\Models\Landlord\LandlordUser $operator */
        $operator = Auth::guard('sanctum')->user();

        try {
            $this->landlordUserService->delete($user, $operator);
        } catch (ValidationException $e) {
            return response()->json(
                [
                    'errors' => [
                        'user' => ['Não é possível excluir o próprio usuário'],
                    ],
                ],
                403
            );
        }

        return response()->json(['message' => 'Usuário do landlord removido com sucesso']);
    }

    public function tenantUserManage(TenantLandlordUserAttachRequest $request): JsonResponse
    {

        $tenant = Tenant::resolve();
        $data = $request->validated();
        $action = null;

        try {
            $method = strtolower($request->method());
            if ($method === 'post') {
                $this->tenantUserRoleManager->assign($data['user_id'], $data['role_id'], $tenant);
                $action = 'create';
            } elseif ($method === 'delete') {
                $this->tenantUserRoleManager->revoke($data['user_id'], $data['role_id'], $tenant);
                $action = 'delete';
            } else {
                abort(422, 'Not found an action for this method.');
            }
        } catch (\Throwable $e) {
            abort(422, 'An error occurred while trying to manage the users for this tenant. Please try again later.');
        }

        $user = $request->user();
        if ($user && $action) {
            $this->telemetry->emit(
                event: 'tenant_user_managed',
                userId: (string) $user->_id,
                properties: [
                    'target_user_id' => $data['user_id'],
                    'role_id' => $data['role_id'],
                    'action' => $action,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json();
    }
}
