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
     * @param array<string, mixed> $payload
     */
    public function registerDevice(Authenticatable $user, array $payload): void;

    /**
     * @param array<int, string> $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function unregisterDevice(Authenticatable $user, array $payload): void;

    public function findUserForAccount(string $accountId, ?string $userId, ?string $email): ?Authenticatable;

    public function findUserForTenant(?string $userId, ?string $email): ?Authenticatable;

    /**
     * @param callable(Authenticatable): void $callback
     */
    public function chunkUsers(?string $accountId, int $chunkSize, callable $callback): void;
}

