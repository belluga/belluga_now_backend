<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\PushMessage;
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

        $responses = [];
        $accepted = 0;
        foreach (array_chunk($tokens, $batchSize) as $chunk) {
            $batchId = (string) Str::uuid();
            $response = $this->fcmClient->send($message, $chunk);
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
                    'batch_id' => $batchId,
                    'token_hash' => hash('sha256', $token),
                    'status' => $entry['status'] ?? 'failed',
                    'error_code' => $entry['error_code'] ?? null,
                    'error_message' => $entry['error_message'] ?? null,
                    'provider_message_id' => $entry['provider_message_id'] ?? null,
                ]);
            }
        }

        return [
            'accepted_count' => $accepted,
            'responses' => $responses,
        ];
    }
}
