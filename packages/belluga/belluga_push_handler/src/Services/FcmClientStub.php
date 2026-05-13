<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Contracts\FcmTopicSenderContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;

class FcmClientStub implements FcmClientContract, FcmTopicSenderContract
{
    /**
     * @param  array<int, string>  $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens, string $messageInstanceId, Carbon $expiresAt, int $ttlMinutes): array
    {
        return [
            'accepted_count' => count($tokens),
            'responses' => array_map(static fn (string $token): array => [
                'token' => $token,
                'status' => 'accepted',
                'provider_message_id' => 'stub',
            ], $tokens),
        ];
    }

    /**
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function sendTopic(PushMessage $message, string $topic, string $messageInstanceId, Carbon $expiresAt, int $ttlMinutes): array
    {
        return [
            'accepted_count' => 1,
            'responses' => [[
                'topic' => $topic,
                'status' => 'accepted',
                'provider_message_id' => 'stub',
            ]],
        ];
    }
}
