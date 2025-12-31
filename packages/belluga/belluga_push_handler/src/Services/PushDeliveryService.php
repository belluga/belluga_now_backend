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
        $batchId = (string) Str::uuid();
        $response = $this->fcmClient->send($message, $tokens);

        foreach ($response['responses'] ?? [] as $entry) {
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

        return $response;
    }
}
