<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\AccountRoleTemplateService;
use App\Http\Api\v1\Requests\AccountRoleTemplatesStoreRequest;
use App\Http\Api\v1\Requests\AccountRolesDeleteRequest;
use App\Http\Api\v1\Requests\AccountRolesUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;

class AccountRolesTemplatesController extends Controller
{
    public function __construct(
        private readonly AccountRoleTemplateService $roleTemplateService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $roles = AccountRoleTemplate::when($request->has('archived'), fn ($query, $name) => $query->onlyTrashed())
            ->paginate(15);

        return response()->json($roles);
    }

    public function store(AccountRoleTemplatesStoreRequest $request): JsonResponse
    {
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $role = $this->roleTemplateService->create($account, $request->validated());

        $actor = $request->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_role_created',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'role_id' => (string) $role->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $role,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $role_id = (string) $request->route('role_id');
        $account = Account::current();

        $role = $account->roleTemplates()
            ->where('_id', new ObjectId($role_id))
            ->firstOrFail();

        return response()->json([
            'data' => $role,
        ]);
    }

    public function update(AccountRolesUpdateRequest $request): JsonResponse
    {
        $account = Account::current();

        $role = $account->roleTemplates()
            ->where('_id', new ObjectId($request->route('role_id')))
            ->firstOrFail();

        if (empty($request->validated())) {
            return response()->json([
                'message' => 'Send at least one field to update.',
                'errors' => [
                    'empty' => [
                        'Send at least one field to update.'
                    ],
                ],
            ], 422);
        }

        $validated = $request->validated();
        $updated = $this->roleTemplateService->update($role, $validated);

        $actor = $request->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_role_updated',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'role_id' => (string) $role->_id,
                    'changed_fields' => array_keys($validated),
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function destroy(AccountRolesDeleteRequest $request): JsonResponse
    {
        if ($request->route('role_id') === $request->validated()['background_role_id']) {
            return response()->json([
                'message' => 'Role ID background should be different from the role ID to be deleted.',
                'errors' => [
                    'role_id' => [
                        'Role ID background should be different from the role ID to be deleted.'
                    ],
                ],
            ], 422);
        }

        $account = Account::current();

        $roleToDelete = $account->roleTemplates()
            ->where('_id', new ObjectId($request->route('role_id')))
            ->firstOrFail();

        $fallbackRole = $account->roleTemplates()
            ->where('_id', new ObjectId($request->validated()['background_role_id']))
            ->firstOrFail();

        try {
            $this->roleTemplateService->delete($account, $roleToDelete, $fallbackRole);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Erro ao excluir role. Tente novamente mais tarde.',
                'errors' => [
                    'database' => [
                        'Erro ao excluir role. Tente novamente mais tarde.'
                    ],
                ],
            ], 422);
        }

        $actor = $request->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_role_deleted',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'role_id' => (string) $roleToDelete->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function restore(Request $request): JsonResponse
    {
        $account = Account::current();
        $role = $this->roleTemplateService->restore(
            $account,
            (string) $request->route('role_id')
        );

        $actor = request()->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_role_restored',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'role_id' => (string) $role->_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $role,
        ]);
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $account = Account::current();
        $this->roleTemplateService->forceDelete(
            $account,
            (string) $request->route('role_id')
        );

        $actor = request()->user();
        if ($actor) {
            $this->telemetry->emit(
                event: 'account_role_force_deleted',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'role_id' => $role_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json([], 200);
    }
}
