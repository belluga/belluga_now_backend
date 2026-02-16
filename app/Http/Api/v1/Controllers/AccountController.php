<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountQueryService;
use App\Application\Accounts\AccountRoleTemplateQueryService;
use App\Application\Accounts\AccountUserQueryService;
use App\Http\Api\v1\Requests\AccountStoreRequest;
use App\Http\Api\v1\Requests\AccountUpdateRequest;
use App\Http\Api\v1\Requests\AccountUserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountManagementService $accountService,
        private readonly AccountQueryService $accountQueryService,
        private readonly AccountUserQueryService $accountUserQueryService,
        private readonly AccountRoleTemplateQueryService $accountRoleTemplateQueryService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15) ?: 15;

        $paginator = $this->accountService->paginateForUser(
            auth()->guard('sanctum')->user(),
            $request->boolean('archived'),
            $perPage,
            $request->query()
        );

        return response()->json($paginator->toArray());
    }

    public function store(AccountStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        if ($actor) {
            $validated['created_by'] = (string) $actor->_id;
            $validated['created_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $validated['created_by_type'];
        }

        $result = $this->accountService->create($validated);

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'account_created',
                userId: (string) $user->_id,
                properties: [
                    'account_id' => (string) $result['account']->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => [
                'account' => $this->accountQueryService->format($result['account']),
                'role' => $result['role'],
            ],
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = $this->accountQueryService->findBySlugOrFail($account_slug);

        return response()->json([
            'data' => $this->accountQueryService->format($account),
        ]);
    }

    public function update(
        AccountUpdateRequest $request
    ): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = $this->accountQueryService->findBySlugOrFail($account_slug);

        $validated = $request->validated();
        $actor = $request->user();

        if ($actor) {
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
        }
        $updated = $this->accountService->update($account, $validated);

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'account_updated',
                userId: (string) $user->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'changed_fields' => array_keys($validated),
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $this->accountQueryService->format($updated),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = $this->accountQueryService->findBySlugOrFail($account_slug);

        $this->accountService->delete($account);

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'account_deleted',
                userId: (string) $user->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function restore(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = $this->accountQueryService->findBySlugOrFail($account_slug, true);

        $restored = $this->accountService->restore($account);

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'account_restored',
                userId: (string) $user->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $restored,
        ]);
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = $this->accountQueryService->findBySlugOrFail($account_slug, true);

        $this->accountService->forceDelete($account);

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'account_force_deleted',
                userId: (string) $user->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function accountUserManage(
        AccountUserAttachRequest $request
    ): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $user_id = (string) $request->route('user_id');
        $role_id = (string) $request->route('role_id');
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $user = $this->accountUserQueryService->findByIdForAccountOrFail($account, $user_id);
        $role = $this->accountRoleTemplateQueryService->findByIdForAccountOrFail($account, $role_id);

        $method = strtolower($request->method());

        if ($method === 'post') {
            $this->accountService->attachUser($account, $user, $role);
            $event = 'account_user_role_attached';
        } elseif ($method === 'delete') {
            $this->accountService->detachUser($account, $user, $role);
            $event = 'account_user_role_removed';
        } else {
            abort(422, 'Not found an action for this method.');
        }

        $actor = $request->user();
        if ($actor) {
            $this->telemetry->emit(
                event: $event,
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'target_user_id' => (string) $user->_id,
                    'role_id' => (string) $role->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json();
    }
}
