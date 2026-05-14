<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Http\Requests\PushMessageSendRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushAudienceTopologyClassifier;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushDeviceService;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

class PushMessageSendController
{
    public function __construct(
        private readonly PushRecipientResolver $recipientResolver,
        private readonly PushDeliveryService $deliveryService,
        private readonly PushDeviceService $pushDeviceService,
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushUserGatewayContract $users,
        private readonly PushAudienceTopologyClassifier $audienceTopology,
    ) {}

    public function __invoke(PushMessageSendRequest $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'tenant')
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

        try {
            $this->audienceTopology->assertIndividualDirect($message);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Transactional direct send only supports canonical individual direct audiences.',
                'errors' => $exception->errors(),
                'reason' => 'unsupported_audience',
            ], 422);
        }

        $recipientUserId = $this->audienceTopology->directRecipientUserId($message);
        if (! is_string($recipientUserId) || $recipientUserId === '') {
            return response()->json(['ok' => false, 'reason' => 'user_not_found'], 404);
        }

        $user = $this->users->findUserForTenant($recipientUserId, null);
        if (! $user) {
            return response()->json(['ok' => false, 'reason' => 'user_not_found'], 404);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'tenant',
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $payload = $request->validated();
        $tokens = $this->recipientResolver->resolveDirectTokens(
            $message,
            'tenant',
            null,
            isset($payload['device_id']) ? (string) $payload['device_id'] : null,
        );

        if ($tokens === []) {
            return response()->json(['ok' => false, 'reason' => 'no_tokens'], 422);
        }

        $isDryRun = (bool) ($payload['dry_run'] ?? false);
        $messageInstanceId = null;
        if (! $isDryRun) {
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

            $acceptedCount = (int) ($response['accepted_count'] ?? 0);
            if ($acceptedCount < 1) {
                return response()->json([
                    'ok' => false,
                    'reason' => 'delivery_failed',
                ], 422);
            }

            $metrics = $message->metrics ?? [];
            $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + $acceptedCount;
            $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;
            $metrics['delivery_topology_counts'] = $this->incrementDirectSendMetric(
                is_array($metrics['delivery_topology_counts'] ?? null) ? $metrics['delivery_topology_counts'] : []
            );
            $metrics['last_delivery_topology'] = PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT;
            $message->metrics = $metrics;
            $message->save();
        }

        $responsePayload = [
            'ok' => true,
            'push_message_id' => (string) $message->_id,
            'recipient_user_id' => $recipientUserId,
            'delivery_topology' => PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT,
            'delivery_status' => $isDryRun ? 'dry_run' : 'accepted',
        ];
        if (is_string($messageInstanceId) && $messageInstanceId !== '') {
            $responsePayload['message_instance_id'] = $messageInstanceId;
        }

        if (App::environment('local') && isset($response)) {
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
                'message_instance_id' => $response['message_instance_id'] ?? null,
                'delivery_topology' => $response['delivery_topology'] ?? PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT,
            ];
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
     * @param  array<string, mixed>  $counts
     * @return array<string, int>
     */
    private function incrementDirectSendMetric(array $counts): array
    {
        $counts[PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT] = (int) ($counts[PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT] ?? 0) + 1;
        $counts[PushAudienceTopologyClassifier::CHANNEL_TOPIC] = (int) ($counts[PushAudienceTopologyClassifier::CHANNEL_TOPIC] ?? 0);

        return $counts;
    }
}
