<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushRecipientResolver
{
    public function __construct(
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushUserGatewayContract $users
    ) {}

    /**
     * @return array<int, string>
     */
    public function resolveTokens(PushMessage $message, string $scope, ?string $accountId): array
    {
        $result = $this->resolveTokensWithUsers($message, $scope, $accountId);

        return $result['tokens'];
    }

    /**
     * @return array{tokens: array<int, string>, token_user_map: array<string, string>}
     */
    public function resolveTokensWithUsers(PushMessage $message, string $scope, ?string $accountId): array
    {
        $tokens = [];
        $tokenUserMap = [];

        $this->users->chunkUsers(
            accountId: $scope === 'account' ? $accountId : null,
            chunkSize: 200,
            callback: function (Authenticatable $user) use ($message, $scope, $accountId, &$tokens, &$tokenUserMap): void {
                if (! $this->audienceService->isEligible($user, $message, [
                    'scope' => $scope,
                    'account_id' => $accountId,
                ])) {
                    return;
                }

                $userId = $this->users->userId($user);
                if ($userId === null || $userId === '') {
                    return;
                }

                foreach ($this->users->activePushTokens($user) as $token) {
                    $tokens[$token] = true;
                    $tokenUserMap[$token] = $userId;
                }
            }
        );

        return [
            'tokens' => array_keys($tokens),
            'token_user_map' => $tokenUserMap,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function tokensForUser(Authenticatable $user): array
    {
        return $this->users->activePushTokens($user);
    }
}
