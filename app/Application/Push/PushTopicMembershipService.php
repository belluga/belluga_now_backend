<?php

declare(strict_types=1);

namespace App\Application\Push;

use Belluga\PushHandler\Contracts\PushTopicTransportContract;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Services\PushCredentialService;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;

class PushTopicMembershipService
{
    public function __construct(
        private readonly PushTopicTransportContract $transport,
        private readonly PushUserTopicProjectionService $projection,
        private readonly PushChannelNamingService $naming,
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly PushCredentialService $credentials,
    ) {}

    public function syncTokenForUser(string $userId, string $pushToken): void
    {
        $this->syncTokensForUser($userId, [$pushToken]);
    }

    /**
     * @param  array<int, string>  $pushTokens
     */
    public function syncTokensForUser(string $userId, array $pushTokens): void
    {
        $userId = trim($userId);
        $tokens = collect($pushTokens)
            ->map(static fn (mixed $token): string => trim((string) $token))
            ->filter(static fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        if ($userId === '' || $tokens === [] || ! $this->isRuntimeReady()) {
            return;
        }

        $this->transport->unsubscribeFromAll($tokens);

        foreach ($this->projection->topicsForUserId($userId) as $topic) {
            $this->transport->subscribe($topic, $tokens);
        }
    }

    /**
     * @param  array<int, string>  $tokens
     */
    public function unsubscribeTokensFromAll(array $tokens): void
    {
        if ($tokens === [] || ! $this->isRuntimeReady()) {
            return;
        }

        $this->transport->unsubscribeFromAll($tokens);
    }

    public function syncUserFavoriteProfileMembership(string $userId, string $accountProfileId): void
    {
        $topic = $this->naming->favoriteAccountProfileTopic($accountProfileId);
        if ($topic === '') {
            return;
        }

        if ($this->projection->userHasFavoriteAccountProfile($userId, $accountProfileId)) {
            $this->subscribeUserTokensToTopic($userId, $topic);

            return;
        }

        $this->unsubscribeUserTokensFromTopic($userId, $topic);
    }
    public function syncUserConfirmedEventMembership(string $userId, string $eventId): void
    {
        $topic = $this->naming->confirmedEventTopic($eventId);
        if ($topic === '') {
            return;
        }

        if ($this->projection->userHasConfirmedEvent($userId, $eventId)) {
            $this->subscribeUserTokensToTopic($userId, $topic);

            return;
        }

        $this->unsubscribeUserTokensFromTopic($userId, $topic);
    }
    /**
     * @return array<int, string>
     */
    private function activeTokensForUserId(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        return PushDevice::query()
            ->where('account_user_id', $userId)
            ->where('is_active', true)
            ->pluck('push_token')
            ->map(static fn (mixed $token): string => trim((string) $token))
            ->filter(static fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function subscribeUserTokensToTopic(string $userId, string $topic): void
    {
        $topic = trim($topic);
        if ($topic === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $tokens = $this->activeTokensForUserId($userId);
        if ($tokens === []) {
            return;
        }

        $this->transport->subscribe($topic, $tokens);
    }

    private function unsubscribeUserTokensFromTopic(string $userId, string $topic): void
    {
        $topic = trim($topic);
        if ($topic === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $tokens = $this->activeTokensForUserId($userId);
        if ($tokens === []) {
            return;
        }

        $this->transport->unsubscribe($topic, $tokens);
    }

    private function isRuntimeReady(): bool
    {
        if (($this->pushSettings->resolvedPushConfig()['enabled'] ?? false) !== true) {
            return false;
        }

        if (! $this->pushSettings->hasRequiredFirebaseConfig($this->pushSettings->currentFirebaseConfig())) {
            return false;
        }

        try {
            return $this->credentials->current() !== null;
        } catch (MultiplePushCredentialsException) {
            return false;
        }
    }
}
