<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;

interface FcmTopicSenderContract
{
    /**
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function sendTopic(PushMessage $message, string $topic, string $messageInstanceId, Carbon $expiresAt, int $ttlMinutes): array;
}
