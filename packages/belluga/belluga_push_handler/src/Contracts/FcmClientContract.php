<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;

interface FcmClientContract
{
    /**
     * @param array<int, string> $tokens
     * @return array{accepted_count:int, responses: array<int, array<string, mixed>>}
     */
    public function send(PushMessage $message, array $tokens): array;
}
