<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Accounts\AccountUserCredentialService;
use App\Http\Api\v1\Requests\CredentialLinkRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;

class AccountUserCredentialController extends Controller
{
    public function __construct(
        private readonly AccountUserCredentialService $credentialService
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

        return response()->json([
            'data' => [
                'credentials' => $updatedUser->credentials,
            ],
        ]);
    }
}
