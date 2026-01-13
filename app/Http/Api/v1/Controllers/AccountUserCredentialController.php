<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Accounts\AccountUserCredentialService;
use App\Http\Api\v1\Requests\CredentialLinkRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;

class AccountUserCredentialController extends Controller
{
    public function __construct(
        private readonly AccountUserCredentialService $credentialService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function store(CredentialLinkRequest $request, string $accountSlug, string $userId): JsonResponse
    {
        $user = AccountUser::query()
            ->where('_id', new ObjectId($userId))
            ->firstOrFail();

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

    public function destroy(Request $request, string $accountSlug, string $userId, string $credentialId): JsonResponse
    {
        $user = AccountUser::query()
            ->where('_id', new ObjectId($userId))
            ->firstOrFail();

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
