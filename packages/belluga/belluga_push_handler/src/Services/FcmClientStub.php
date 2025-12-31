<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class FcmClientStub implements FcmClientContract
{
    /**
     * @param array<int, string> $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens): array
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
}
