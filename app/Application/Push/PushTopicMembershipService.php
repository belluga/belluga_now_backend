<?php

declare(strict_types=1);

namespace App\Application\Push;

use Belluga\PushHandler\Contracts\PushTopicTransportContract;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Services\PushCredentialService;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;

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
        $pushToken = trim($pushToken);
        if ($pushToken === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $this->transport->unsubscribeFromAll([$pushToken]);

        foreach ($this->projection->topicsForUserId($userId) as $topic) {
            $this->transport->subscribe($topic, [$pushToken]);
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

    public function subscribeUserToFavoriteProfile(string $userId, string $accountProfileId): void
    {
        $topic = $this->naming->favoriteAccountProfileTopic($accountProfileId);
        if ($topic === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $tokens = $this->activeTokensForUserId($userId);
        if ($tokens === []) {
            return;
        }

        $this->transport->subscribe($topic, $tokens);
    }

    public function unsubscribeUserFromFavoriteProfile(string $userId, string $accountProfileId): void
    {
        $topic = $this->naming->favoriteAccountProfileTopic($accountProfileId);
        if ($topic === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $tokens = $this->activeTokensForUserId($userId);
        if ($tokens === []) {
            return;
        }

        $this->transport->unsubscribe($topic, $tokens);
    }

    public function subscribeUserToConfirmedOccurrence(string $userId, string $occurrenceId): void
    {
        $topic = $this->naming->confirmedOccurrenceTopic($occurrenceId);
        if ($topic === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $tokens = $this->activeTokensForUserId($userId);
        if ($tokens === []) {
            return;
        }

        $this->transport->subscribe($topic, $tokens);
    }

    public function unsubscribeUserFromConfirmedOccurrence(string $userId, string $occurrenceId): void
    {
        $topic = $this->naming->confirmedOccurrenceTopic($occurrenceId);
        if ($topic === '' || ! $this->isRuntimeReady()) {
            return;
        }

        $tokens = $this->activeTokensForUserId($userId);
        if ($tokens === []) {
            return;
        }

        $this->transport->unsubscribe($topic, $tokens);
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
