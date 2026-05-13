<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface PushUserGatewayContract
{
    public function supports(Authenticatable $user): bool;

    public function userId(Authenticatable $user): ?string;

    /**
     * @return array<int, string>
     */
    public function activePushTokens(Authenticatable $user): array;

    /**
     * @return array<int, string>
     */
    public function activePushTokensForDevice(Authenticatable $user, string $deviceId): array;

    /**
     * @return array<int, string>
     */
    public function activePushTokensForRecipient(?string $accountId, string $userId, ?string $deviceId = null): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function registerDevice(Authenticatable $user, array $payload): void;

    /**
     * @param  array<int, string>  $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function unregisterDevice(Authenticatable $user, array $payload): void;

    public function findUserForAccount(string $accountId, ?string $userId, ?string $email): ?Authenticatable;

    public function findUserForTenant(?string $userId, ?string $email): ?Authenticatable;

    /**
     * @return array<int, string>
     */
    public function resolveAccountIds(Authenticatable $user): array;

    /**
     * @param  array<int, string>  $userIds
     */
    public function countActivePushTargetsByUserIds(?string $accountId, array $userIds): int;

    public function countActivePushTargets(?string $accountId): int;

    /**
     * @param  array<int, string>  $userIds
     * @param  callable(array<int, array{id:string,user_id:string,push_token:string}>): void  $callback
     */
    public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void;

    /**
     * @param  callable(array<int, array{id:string,user_id:string,push_token:string}>): void  $callback
     */
    public function chunkActivePushTargets(?string $accountId, int $chunkSize, callable $callback): void;

    /**
     * @param  array<int, string>  $sourceUserIds
     * @param  array<int, string>  $targetAccountIds
     */
    public function reassignPushDevices(string $targetUserId, array $sourceUserIds, array $targetAccountIds = []): void;

    /**
     * @param  array<int, string>  $accountIds
     */
    public function syncPushDeviceAccountIds(string $userId, array $accountIds): void;

    public function deactivatePushDevicesForUser(string $userId): void;
}
