<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class PushDeliveryService
{
    public function __construct(
        private readonly FcmClientContract $fcmClient
    ) {
    }

    /**
     * @param array<int, string> $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function deliver(PushMessage $message, array $tokens): array
    {
        $batchSize = (int) config('belluga_push_handler.fcm.max_batch_size', 500);
        if ($batchSize <= 0) {
            $batchSize = 500;
        }

        [$expiresAt, $ttlMinutes] = $this->resolveDeliveryTiming($message);
        $messageInstanceId = (string) Str::uuid();
        $responses = [];
        $accepted = 0;
        foreach (array_chunk($tokens, $batchSize) as $chunk) {
            $batchId = (string) Str::uuid();
            $response = $this->fcmClient->send($message, $chunk, $messageInstanceId, $expiresAt, $ttlMinutes);
            $accepted += (int) ($response['accepted_count'] ?? 0);

            $batchResponses = $response['responses'] ?? [];
            if (is_array($batchResponses)) {
                $responses = array_merge($responses, $batchResponses);
            }

            foreach ($batchResponses as $entry) {
                $token = $entry['token'] ?? null;
                if (! is_string($token) || $token === '') {
                    continue;
                }

                PushDeliveryLog::create([
                    'push_message_id' => (string) $message->_id,
                    'message_instance_id' => $messageInstanceId,
                    'batch_id' => $batchId,
                    'token_hash' => hash('sha256', $token),
                    'status' => $entry['status'] ?? 'failed',
                    'error_code' => $entry['error_code'] ?? null,
                    'error_message' => $entry['error_message'] ?? null,
                    'provider_message_id' => $entry['provider_message_id'] ?? null,
                    'expires_at' => $expiresAt->toISOString(),
                    'ttl_minutes' => $ttlMinutes,
                ]);
            }
        }

        return [
            'accepted_count' => $accepted,
            'responses' => $responses,
            'message_instance_id' => $messageInstanceId,
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

        $maxTtlDays = TenantPushSettings::current()?->getPushMaxTtlDays() ?? 30;
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
}
