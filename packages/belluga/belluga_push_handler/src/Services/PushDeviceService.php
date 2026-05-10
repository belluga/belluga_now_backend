<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Domain\Events\PushDeviceRegistered;
use Belluga\PushHandler\Domain\Events\PushDeviceUnregistered;
use Belluga\PushHandler\Domain\Events\PushTokensInvalidated;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Illuminate\Contracts\Auth\Authenticatable;

class PushDeviceService
{
    public function __construct(
        private readonly PushUserGatewayContract $users
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function register(Authenticatable $user, array $payload): void
    {
        if (! $this->users->supports($user)) {
            return;
        }

        $userId = $this->users->userId($user);
        $pushToken = trim((string) ($payload['push_token'] ?? ''));
        $this->users->registerDevice($user, $payload);

        if ($userId === null || $userId === '' || $pushToken === '') {
            return;
        }

        event(new PushDeviceRegistered($userId, $pushToken));
    }

    /**
     * @param  array<int, string>  $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void
    {
        if (! $this->users->supports($user) || $tokens === []) {
            return;
        }

        $normalizedTokens = array_values(array_filter(array_map(
            static fn (mixed $token): string => trim((string) $token),
            $tokens,
        ), static fn (string $token): bool => $token !== ''));
        if ($normalizedTokens === []) {
            return;
        }

        $this->users->invalidateTokens($user, $normalizedTokens);

        event(new PushTokensInvalidated($normalizedTokens));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function unregister(Authenticatable $user, array $payload): void
    {
        if (! $this->users->supports($user)) {
            return;
        }

        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        $tokens = $deviceId !== '' ? $this->users->activePushTokensForDevice($user, $deviceId) : [];
        $this->users->unregisterDevice($user, $payload);

        if ($tokens === []) {
            return;
        }

        event(new PushDeviceUnregistered($tokens));
    }
}
