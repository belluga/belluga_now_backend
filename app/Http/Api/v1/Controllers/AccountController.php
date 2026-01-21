<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

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
        private readonly AccountManagementService $accountService
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
        $result = $this->accountService->create($request->validated());

        return response()->json([
            'data' => $result,
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->firstOrFail();

        return response()->json([
            'data' => $account,
        ]);
    }

    public function update(
        AccountUpdateRequest $request
    ): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->firstOrFail();

        $updated = $this->accountService->update($account, $request->validated());

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::where('slug', $account_slug)->firstOrFail();

        $this->accountService->delete($account);

        return response()->json();
    }

    public function restore(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::onlyTrashed()->where('slug', $account_slug)->firstOrFail();

        $restored = $this->accountService->restore($account);

        return response()->json([
            'data' => $restored,
        ]);
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $account_slug = (string) $request->route('account_slug');
        $account = Account::onlyTrashed()->where('slug', $account_slug)->firstOrFail();

        $this->accountService->forceDelete($account);

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
        } elseif ($method === 'delete') {
            $this->accountService->detachUser($account, $user, $role);
        } else {
            abort(422, 'Not found an action for this method.');
        }

        return response()->json();
    }
}
