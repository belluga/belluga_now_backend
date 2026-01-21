<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\AccountManagementService;
use App\Http\Api\v1\Requests\AccountStoreRequest;
use App\Http\Api\v1\Requests\AccountUpdateRequest;
use App\Http\Api\v1\Requests\AccountUserAttachRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountManagementService $accountService,
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
            'data' => $result,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->firstOrFail();

        return response()->json([
            'data' => [
                'id' => (string) $account->_id,
                'name' => $account->name,
                'slug' => $account->slug,
                'document' => $account->document,
                'organization_id' => $account->organization_id ?? null,
                'created_at' => $account->created_at?->toJSON(),
                'updated_at' => $account->updated_at?->toJSON(),
                'deleted_at' => $account->deleted_at?->toJSON(),
            ],
        ]);
    }

    public function update(
        AccountUpdateRequest $request
    ): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->firstOrFail();

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
            'data' => [
                'id' => (string) $updated->_id,
                'name' => $updated->name,
                'slug' => $updated->slug,
                'document' => $updated->document,
                'organization_id' => $updated->organization_id ?? null,
                'created_at' => $updated->created_at?->toJSON(),
                'updated_at' => $updated->updated_at?->toJSON(),
                'deleted_at' => $updated->deleted_at?->toJSON(),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->firstOrFail();

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
        $account = Account::onlyTrashed()->where('slug', $account_slug)->firstOrFail();

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
        $account = Account::onlyTrashed()->where('slug', $account_slug)->firstOrFail();

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

        $user = AccountUser::query()
            ->where('_id', new ObjectId($user_id))
            ->firstOrFail();

        $role = $account->roleTemplates()
            ->where('_id', new ObjectId($role_id))
            ->firstOrFail();

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
