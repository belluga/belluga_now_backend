<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Illuminate\Contracts\Auth\Authenticatable;

class PushDeviceService
{
    public function __construct(
        private readonly PushUserGatewayContract $users
    ) {
    }

    /**
     * @param Authenticatable $user
     * @param array<string, mixed> $payload
     */
    public function register(Authenticatable $user, array $payload): void
    {
        if (! $this->users->supports($user)) {
            return;
        }

        $this->users->registerDevice($user, $payload);
    }

    /**
     * @param Authenticatable $user
     * @param array<int, string> $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void
    {
        if (! $this->users->supports($user) || $tokens === []) {
            return;
        }

        $this->users->invalidateTokens($user, $tokens);
    }

    /**
     * @param Authenticatable $user
     * @param array<string, mixed> $payload
     */
    public function unregister(Authenticatable $user, array $payload): void
    {
        if (! $this->users->supports($user)) {
            return;
        }

        $this->users->unregisterDevice($user, $payload);
    }
}
