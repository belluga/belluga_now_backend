<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\AccountUserCredentialService;
use App\Application\Accounts\AccountUserQueryService;
use App\Http\Api\v1\Requests\CredentialLinkRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountUserCredentialController extends Controller
{
    public function __construct(
        private readonly AccountUserCredentialService $credentialService,
        private readonly AccountUserQueryService $accountUserQueryService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function store(
        CredentialLinkRequest $request
    ): JsonResponse
    {
        $userId = (string) $request->route('user_id');
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $user = $this->accountUserQueryService->findByIdForAccountOrFail($account, $userId);

        $result = $this->credentialService->link($user, $request->validated());

        /** @var AccountUser $freshUser */
        $freshUser = $result['user'];
        $credential = $result['credential'];

        $actor = $request->user();
        $account = Account::current();
        if ($actor && $account) {
            $credentialId = (string) ($credential['_id'] ?? $credential['id'] ?? '');
            $this->telemetry->emit(
                event: 'account_user_credential_added',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'target_user_id' => (string) $user->_id,
                    'credential_id' => $credentialId,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => [
                'credentials' => $freshUser->credentials,
                'credential' => $result['credential'],
            ],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $userId = (string) $request->route('user_id');
        $credentialId = (string) $request->route('credential_id');
        $account = Account::current();

        if (! $account) {
            abort(401, 'Account context not available.');
        }

        $user = $this->accountUserQueryService->findByIdForAccountOrFail($account, $userId);

        $updatedUser = $this->credentialService->unlink($user, $credentialId);

        $actor = $request->user();
        $account = Account::current();
        if ($actor && $account) {
            $this->telemetry->emit(
                event: 'account_user_credential_removed',
                userId: (string) $actor->_id,
                properties: [
                    'account_id' => (string) $account->_id,
                    'target_user_id' => (string) $user->_id,
                    'credential_id' => $credentialId,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => [
                'credentials' => $updatedUser->credentials,
            ],
        ]);
    }
}
