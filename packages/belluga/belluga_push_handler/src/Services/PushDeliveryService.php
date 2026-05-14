<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Contracts\FcmTopicSenderContract;
use Belluga\PushHandler\Contracts\PushTelemetryEmitterContract;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PushDeliveryService
{
    public function __construct(
        private readonly FcmClientContract $fcmClient,
        private readonly FcmTopicSenderContract $fcmTopicSender,
        private readonly PushTelemetryEmitterContract $telemetryEmitter,
        private readonly PushSettingsKernelBridge $pushSettings
    ) {}

    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $tokenUserMap
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>, message_instance_id:string, delivery_topology:string}
     */
    public function deliver(PushMessage $message, array $tokens, array $tokenUserMap = []): array
    {
        $chunkSize = (int) config('belluga_push_handler.fcm.direct_send_chunk_size', 500);
        if ($chunkSize <= 0) {
            $chunkSize = 500;
        }

        [$expiresAt, $ttlMinutes] = $this->resolveDeliveryTiming($message);
        $messageInstanceId = (string) Str::uuid();
        $responses = [];
        $accepted = 0;
        $telemetryUserIds = [];

        foreach (array_chunk($tokens, $chunkSize) as $chunk) {
            $batchId = (string) Str::uuid();
            $response = $this->fcmClient->send($message, $chunk, $messageInstanceId, $expiresAt, $ttlMinutes);
            $accepted += (int) ($response['accepted_count'] ?? 0);

            $batchResponses = is_array($response['responses'] ?? null) ? $response['responses'] : [];
            if ($batchResponses !== []) {
                $responses = array_merge($responses, $batchResponses);
            }

            foreach ($batchResponses as $entry) {
                $token = $entry['token'] ?? null;
                if (! is_string($token) || $token === '') {
                    continue;
                }

                $status = $entry['status'] ?? 'failed';
                if ($status === 'accepted' && $message->type === 'invite_received') {
                    $userId = $tokenUserMap[$token] ?? null;
                    if (is_string($userId) && $userId !== '') {
                        $telemetryUserIds[$userId] = true;
                    }
                }
            }

            $this->recordResponses(
                message: $message,
                messageInstanceId: $messageInstanceId,
                batchId: $batchId,
                responses: $batchResponses,
                expiresAt: $expiresAt,
                ttlMinutes: $ttlMinutes,
                deliveryTopology: PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT,
            );
        }

        if ($message->type === 'invite_received' && $telemetryUserIds !== []) {
            foreach (array_keys($telemetryUserIds) as $userId) {
                $this->telemetryEmitter->emit(
                    event: 'invite_received',
                    userId: (string) $userId,
                    properties: [
                        'push_message_id' => (string) $message->_id,
                        'message_instance_id' => $messageInstanceId,
                        'push_type' => (string) ($message->type ?? ''),
                    ],
                    idempotencyKey: implode(':', [
                        'invite_received',
                        (string) $message->_id,
                        $messageInstanceId,
                        (string) $userId,
                    ]),
                    source: 'push',
                    context: [
                        'actor' => ['type' => 'user', 'id' => (string) $userId],
                        'object' => ['type' => 'push_message', 'id' => (string) $message->_id],
                        'target' => ['type' => 'user', 'id' => (string) $userId],
                        'visibility' => 'tenant',
                    ]
                );
            }
        }

        return [
            'accepted_count' => $accepted,
            'responses' => $responses,
            'message_instance_id' => $messageInstanceId,
            'delivery_topology' => PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT,
        ];
    }

    /**
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>, message_instance_id:string, delivery_topology:string}
     */
    public function deliverToTopic(PushMessage $message, string $topic): array
    {
        [$expiresAt, $ttlMinutes] = $this->resolveDeliveryTiming($message);
        $messageInstanceId = (string) Str::uuid();
        $batchId = (string) Str::uuid();

        $response = $this->fcmTopicSender->sendTopic($message, $topic, $messageInstanceId, $expiresAt, $ttlMinutes);
        $responses = is_array($response['responses'] ?? null) ? $response['responses'] : [];

        $this->recordResponses(
            message: $message,
            messageInstanceId: $messageInstanceId,
            batchId: $batchId,
            responses: $responses,
            expiresAt: $expiresAt,
            ttlMinutes: $ttlMinutes,
            deliveryTopology: PushAudienceTopologyClassifier::CHANNEL_TOPIC,
        );

        return [
            'accepted_count' => (int) ($response['accepted_count'] ?? 0),
            'responses' => $responses,
            'message_instance_id' => $messageInstanceId,
            'delivery_topology' => PushAudienceTopologyClassifier::CHANNEL_TOPIC,
        ];
    }

    /**
     * @return array{0:Carbon, 1:int}
     */
    private function resolveDeliveryTiming(PushMessage $message): array
    {
        $ttlMinutes = $this->resolveTtlMinutes($message);
        if ($ttlMinutes <= 0) {
            throw ValidationException::withMessages([
                'delivery.expires_at' => 'Delivery TTL must be greater than zero.',
            ]);
        }

        $maxTtlDays = $this->pushSettings->resolveMaxTtlDays(7);
        $fcmMaxDays = (int) config('belluga_push_handler.fcm.max_ttl_days', 28);
        $maxAllowedDays = min($maxTtlDays, $fcmMaxDays);
        $maxAllowedMinutes = $maxAllowedDays * 24 * 60;
        if ($ttlMinutes > $maxAllowedMinutes) {
            throw ValidationException::withMessages([
                'delivery.expires_at' => "Computed TTL exceeds max allowed TTL of {$maxAllowedDays} days.",
            ]);
        }

        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);
        $deadline = $message->delivery_deadline_at;
        if ($deadline) {
            $deadlineAt = Carbon::parse($deadline);
            if ($deadlineAt->isPast()) {
                throw ValidationException::withMessages([
                    'delivery_deadline_at' => 'Delivery deadline must be in the future.',
                ]);
            }
            if ($deadlineAt->lt($expiresAt)) {
                $expiresAt = $deadlineAt;
            }
        }

        return [$expiresAt, $ttlMinutes];
    }

    private function resolveTtlMinutes(PushMessage $message): int
    {
        $policy = config('belluga_push_handler.delivery_ttl_minutes', []);
        $type = $message->type;
        if (is_string($type) && $type !== '' && isset($policy[$type])) {
            return (int) $policy[$type];
        }

        if ($type === 'transactional' && isset($policy['transactional'])) {
            return (int) $policy['transactional'];
        }

        if (isset($policy['promotional'])) {
            return (int) $policy['promotional'];
        }

        return (int) ($policy['default'] ?? 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $responses
     */
    private function recordResponses(
        PushMessage $message,
        string $messageInstanceId,
        string $batchId,
        array $responses,
        Carbon $expiresAt,
        int $ttlMinutes,
        string $deliveryTopology,
    ): void {
        foreach ($responses as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $token = isset($entry['token']) && is_string($entry['token']) && $entry['token'] !== ''
                ? $entry['token']
                : null;
            $topic = isset($entry['topic']) && is_string($entry['topic']) && $entry['topic'] !== ''
                ? $entry['topic']
                : null;
            $targetValue = $token ?? $topic;
            if ($targetValue === null) {
                continue;
            }

            PushDeliveryLog::create([
                'push_message_id' => (string) $message->_id,
                'message_instance_id' => $messageInstanceId,
                'batch_id' => $batchId,
                'delivery_topology' => $deliveryTopology,
                'target_type' => $token !== null ? 'token' : 'topic',
                'target_hash' => hash('sha256', $targetValue),
                'token_hash' => $token !== null ? hash('sha256', $token) : null,
                'status' => $entry['status'] ?? 'failed',
                'error_code' => $entry['error_code'] ?? null,
                'error_message' => $entry['error_message'] ?? null,
                'provider_message_id' => $entry['provider_message_id'] ?? null,
                'expires_at' => $expiresAt->toISOString(),
                'ttl_minutes' => $ttlMinutes,
            ]);
        }
    }
}
