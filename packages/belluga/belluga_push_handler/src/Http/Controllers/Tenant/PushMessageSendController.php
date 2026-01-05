<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Http\Requests\PushMessageSendRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushDeviceService;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Illuminate\Http\JsonResponse;

class PushMessageSendController
{
    public function __construct(
        private readonly PushRecipientResolver $recipientResolver,
        private readonly PushDeliveryService $deliveryService,
        private readonly PushDeviceService $pushDeviceService,
        private readonly PushMessageAudienceService $audienceService
    ) {
    }

    public function __invoke(PushMessageSendRequest $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $pushMessageId)
            ->firstOrFail();

        if (! $message->isActive() || $message->isExpired()) {
            return response()->json(['ok' => false, 'reason' => 'inactive'], 422);
        }

        if (($message->type ?? null) !== 'transactional') {
            return response()->json(['ok' => false, 'reason' => 'invalid_type'], 422);
        }

        $payload = $request->validated();

        $user = $this->resolveUser($payload);
        if (! $user) {
            return response()->json(['ok' => false, 'reason' => 'user_not_found'], 404);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'tenant',
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $tokens = $this->recipientResolver->tokensForUser($user);
        if (! empty($payload['device_id'])) {
            $tokens = array_values(array_filter($tokens, static function (string $token) use ($user, $payload): bool {
                foreach ($user->devices ?? [] as $device) {
                    $isActive = $device['is_active'] ?? true;
                    if ($isActive !== true) {
                        continue;
                    }
                    if (($device['device_id'] ?? null) === $payload['device_id'] && ($device['push_token'] ?? null) === $token) {
                        return true;
                    }
                }
                return false;
            }));
        }

        if ($tokens === []) {
            return response()->json(['ok' => false, 'reason' => 'no_tokens'], 422);
        }

        if (! ($payload['dry_run'] ?? false)) {
            $response = $this->deliveryService->deliver($message, $tokens);
            $invalidTokens = $this->extractNotFoundTokens($response);
            if ($invalidTokens !== []) {
                $this->pushDeviceService->invalidateTokens($user, $invalidTokens);
            }

            $metrics = $message->metrics ?? [];
            $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + (int) ($response['accepted_count'] ?? 0);
            $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;
            $message->metrics = $metrics;
            $message->save();
        }

        $responsePayload = [
            'ok' => true,
            'push_message_id' => (string) $message->_id,
            'recipient_user_id' => (string) $user->_id,
            'queued' => true,
        ];

        if (app()->environment('local') && isset($response)) {
            $responses = $response['responses'] ?? [];
            $sanitized = [];
            foreach ($responses as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $token = $entry['token'] ?? null;
                if (is_string($token) && $token !== '') {
                    $entry['token_hash'] = hash('sha256', $token);
                    unset($entry['token']);
                }
                $sanitized[] = $entry;
            }
            $responsePayload['delivery_debug'] = [
                'accepted_count' => (int) ($response['accepted_count'] ?? 0),
                'responses' => $sanitized,
            ];
        }

        return response()->json($responsePayload);
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, string>
     */
    private function extractNotFoundTokens(array $response): array
    {
        $responses = $response['responses'] ?? [];
        if (! is_array($responses)) {
            return [];
        }

        $tokens = [];
        foreach ($responses as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $errorCode = $entry['error_code'] ?? null;
            $token = $entry['token'] ?? null;
            if ($errorCode === 'NOT_FOUND' && is_string($token) && $token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveUser(array $payload): ?AccountUser
    {
        if (! empty($payload['user_id'])) {
            return AccountUser::query()
                ->where('_id', $payload['user_id'])
                ->first();
        }

        if (! empty($payload['email'])) {
            $email = strtolower((string) $payload['email']);
            return AccountUser::query()
                ->where('emails', 'all', [$email])
                ->first();
        }

        return null;
    }
}
