<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushTopicTransportContract;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;
use Throwable;

class KreaitTopicTransportClient implements PushTopicTransportContract
{
    public function __construct(
        private readonly PushCredentialService $credentials
    ) {}

    public function subscribe(string $topic, array $tokens): void
    {
        $topic = trim($topic);
        $tokens = $this->normalizeTokens($tokens);
        if ($topic === '' || $tokens === []) {
            return;
        }

        $messaging = $this->messaging();
        if (! $messaging instanceof Messaging) {
            return;
        }

        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $messaging->subscribeToTopic($topic, $chunk);
            } catch (Throwable $exception) {
                Log::warning('Push topic subscribe failed.', [
                    'topic' => $topic,
                    'token_count' => count($chunk),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function unsubscribe(string $topic, array $tokens): void
    {
        $topic = trim($topic);
        $tokens = $this->normalizeTokens($tokens);
        if ($topic === '' || $tokens === []) {
            return;
        }

        $messaging = $this->messaging();
        if (! $messaging instanceof Messaging) {
            return;
        }

        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $messaging->unsubscribeFromTopic($topic, $chunk);
            } catch (Throwable $exception) {
                Log::warning('Push topic unsubscribe failed.', [
                    'topic' => $topic,
                    'token_count' => count($chunk),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function unsubscribeFromAll(array $tokens): void
    {
        $tokens = $this->normalizeTokens($tokens);
        if ($tokens === []) {
            return;
        }

        $messaging = $this->messaging();
        if (! $messaging instanceof Messaging) {
            return;
        }

        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $messaging->unsubscribeFromAllTopics($chunk);
            } catch (Throwable $exception) {
                Log::warning('Push topic unsubscribe-all failed.', [
                    'token_count' => count($chunk),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function messaging(): ?Messaging
    {
        try {
            $credentials = $this->credentials->current();
        } catch (MultiplePushCredentialsException $exception) {
            Log::warning('Push topic transport skipped due to multiple tenant push credentials.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($credentials === null) {
            return null;
        }

        try {
            $factory = (new Factory())->withServiceAccount([
                'type' => 'service_account',
                'project_id' => (string) $credentials->project_id,
                'client_email' => (string) $credentials->client_email,
                'private_key' => (string) $credentials->private_key,
            ]);

            return $factory->createMessaging();
        } catch (Throwable $exception) {
            Log::warning('Push topic transport messaging bootstrap failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return array<int, string>
     */
    private function normalizeTokens(array $tokens): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $token): string => trim((string) $token),
            $tokens
        ), static fn (string $token): bool => $token !== '')));
    }
}
