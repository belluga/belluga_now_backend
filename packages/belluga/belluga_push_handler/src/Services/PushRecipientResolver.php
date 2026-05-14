<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class PushRecipientResolver
{
    public function __construct(
        private readonly PushUserGatewayContract $users,
        private readonly PushAudienceTopologyClassifier $audienceTopology,
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

        $this->streamResolvedTargetBatches(
            $message,
            $scope,
            $accountId,
            $this->defaultChunkSize(),
            function (array $batch) use (&$tokens, &$tokenUserMap): void {
                foreach ($batch['tokens'] as $token) {
                    $tokens[$token] = true;
                }

                foreach ($batch['token_user_map'] as $token => $userId) {
                    $tokenUserMap[$token] = $userId;
                }
            }
        );

        return [
            'tokens' => array_keys($tokens),
            'token_user_map' => $tokenUserMap,
        ];
    }

    public function countTargets(PushMessage $message, string $scope, ?string $accountId): int
    {
        [$scopedAccountId, $userId] = $this->directRecipientScope($message, $scope, $accountId);

        return $this->users->countActivePushTargetsByUserIds($scopedAccountId, [$userId]);
    }

    /**
     * @param  callable(array{tokens: array<int, string>, token_user_map: array<string, string>}): void  $callback
     */
    public function streamResolvedTargetBatches(
        PushMessage $message,
        string $scope,
        ?string $accountId,
        int $batchSize,
        callable $callback
    ): void {
        [$scopedAccountId, $userId] = $this->directRecipientScope($message, $scope, $accountId);

        $this->users->chunkActivePushTargetsByUserIds(
            $scopedAccountId,
            [$userId],
            $batchSize,
            function (array $targets) use ($callback): void {
                $payload = $this->buildBatchPayload($targets);
                if ($payload['tokens'] !== []) {
                    $callback($payload);
                }
            }
        );
    }

    /**
     * @return array<int, string>
     */
    public function resolveDirectTokens(PushMessage $message, string $scope, ?string $accountId, ?string $deviceId = null): array
    {
        [$scopedAccountId, $userId] = $this->directRecipientScope($message, $scope, $accountId);

        return $this->users->activePushTokensForRecipient($scopedAccountId, $userId, $deviceId);
    }

    /**
     * @return array<int, string>
     */
    public function tokensForUser(Authenticatable $user): array
    {
        return $this->users->activePushTokens($user);
    }

    /**
     * @param  array<int, array{id:string,user_id:string,push_token:string}>  $targets
     * @return array{tokens: array<int, string>, token_user_map: array<string, string>}
     */
    private function buildBatchPayload(array $targets): array
    {
        $tokens = [];
        $tokenUserMap = [];

        foreach ($targets as $target) {
            $token = trim((string) ($target['push_token'] ?? ''));
            $userId = trim((string) ($target['user_id'] ?? ''));
            if ($token === '' || $userId === '') {
                continue;
            }

            $tokens[$token] = true;
            $tokenUserMap[$token] = $userId;
        }

        return [
            'tokens' => array_keys($tokens),
            'token_user_map' => $tokenUserMap,
        ];
    }

    private function defaultChunkSize(): int
    {
        $chunkSize = (int) config('belluga_push_handler.fcm.direct_send_chunk_size', 500);

        return $chunkSize > 0 ? $chunkSize : 500;
    }

    /**
     * @return array{0:?string,1:string}
     */
    private function directRecipientScope(PushMessage $message, string $scope, ?string $accountId): array
    {
        $scopedAccountId = $scope === 'account' ? $accountId : null;
        $userId = $this->audienceTopology->directRecipientUserId($message);
        if ($userId !== null && $userId !== '') {
            return [$scopedAccountId, $userId];
        }

        throw ValidationException::withMessages([
            'audience.type' => 'Explicit recipient materialization is only allowed for individual direct delivery.',
        ]);
    }
}
