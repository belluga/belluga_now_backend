<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushRecipientResolver
{
    public function __construct(
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

        $this->streamResolvedTargetBatches(
            $message,
            $scope,
            $accountId,
            $this->defaultBatchSize(),
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
        $audience = $message->audience ?? [];
        $audienceType = is_string($audience['type'] ?? null) ? $audience['type'] : 'all';
        $scopedAccountId = $scope === 'account' ? $accountId : null;

        if ($audienceType === 'users') {
            return $this->users->countActivePushTargetsByUserIds(
                $scopedAccountId,
                is_array($audience['user_ids'] ?? null) ? $audience['user_ids'] : []
            );
        }

        if ($audienceType === 'all') {
            return $this->users->countActivePushTargets($scopedAccountId);
        }

        return 0;
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
        $audience = $message->audience ?? [];
        $audienceType = is_string($audience['type'] ?? null) ? $audience['type'] : 'all';
        $scopedAccountId = $scope === 'account' ? $accountId : null;

        if ($audienceType === 'users') {
            $this->users->chunkActivePushTargetsByUserIds(
                $scopedAccountId,
                is_array($audience['user_ids'] ?? null) ? $audience['user_ids'] : [],
                $batchSize,
                function (array $targets) use ($callback): void {
                    $payload = $this->buildBatchPayload($targets);
                    if ($payload['tokens'] !== []) {
                        $callback($payload);
                    }
                }
            );

            return;
        }

        // Semantic audiences must be materialized into explicit user IDs before
        // entering the generic push delivery pipeline.
        if ($audienceType !== 'all') {
            return;
        }

        $this->users->chunkActivePushTargets(
            $scopedAccountId,
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

    private function defaultBatchSize(): int
    {
        $batchSize = (int) config('belluga_push_handler.fcm.max_batch_size', 500);

        return $batchSize > 0 ? $batchSize : 500;
    }
}
