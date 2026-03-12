<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Http\Requests\PushMessageSendRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushDeviceService;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PushMessageSendController
{
    public function __construct(
        private readonly PushRecipientResolver $recipientResolver,
        private readonly PushDeliveryService $deliveryService,
        private readonly PushDeviceService $pushDeviceService,
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushAccountContextContract $accountContext,
        private readonly PushUserGatewayContract $users
    ) {}

    public function __invoke(PushMessageSendRequest $request): JsonResponse
    {
        $accountId = $this->accountContext->currentAccountId();
        if ($accountId === null || $accountId === '') {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('partner_id', $accountId)
            ->where('_id', $pushMessageId)
            ->first();

        if (! $message) {
            $exists = PushMessage::query()
                ->where('_id', $pushMessageId)
                ->exists();

            if ($exists) {
                return response()->json(['ok' => false, 'reason' => 'inactive'], 422);
            }

            abort(404);
        }

        if (! $message->isActive() || $message->isExpired()) {
            return response()->json(['ok' => false, 'reason' => 'inactive'], 422);
        }

        if (($message->type ?? null) !== 'transactional') {
            return response()->json(['ok' => false, 'reason' => 'invalid_type'], 422);
        }

        $payload = $request->validated();

        $user = $this->resolveUser($payload, $accountId);
        if (! $user) {
            return response()->json(['ok' => false, 'reason' => 'user_not_found'], 404);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'account',
            'account_id' => $accountId,
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $tokens = $this->recipientResolver->tokensForUser($user);
        if (! empty($payload['device_id'])) {
            $tokens = $this->users->activePushTokensForDevice($user, (string) $payload['device_id']);
        }

        if ($tokens === []) {
            return response()->json(['ok' => false, 'reason' => 'no_tokens'], 422);
        }

        $recipientUserId = $this->users->userId($user);
        if ($recipientUserId === null || $recipientUserId === '') {
            return response()->json(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        $messageInstanceId = null;
        if (! ($payload['dry_run'] ?? false)) {
            try {
                $tokenUserMap = array_fill_keys($tokens, $recipientUserId);
                $response = $this->deliveryService->deliver($message, $tokens, $tokenUserMap);
            } catch (ValidationException $exception) {
                return response()->json([
                    'message' => 'Delivery TTL validation failed.',
                    'errors' => $exception->errors(),
                ], 422);
            }
            $messageInstanceId = $response['message_instance_id'] ?? null;
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
            'recipient_user_id' => $recipientUserId,
            'queued' => true,
        ];
        if (is_string($messageInstanceId) && $messageInstanceId !== '') {
            $responsePayload['message_instance_id'] = $messageInstanceId;
        }

        return response()->json($responsePayload);
    }

    /**
     * @param  array<string, mixed>  $response
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
     * @param  array<string, mixed>  $payload
     */
    private function resolveUser(array $payload, string $accountId): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $userId = isset($payload['user_id']) ? (string) $payload['user_id'] : null;
        $email = isset($payload['email']) ? (string) $payload['email'] : null;

        return $this->users->findUserForAccount(
            $accountId,
            $userId !== '' ? $userId : null,
            $email !== '' ? $email : null
        );
    }
}
