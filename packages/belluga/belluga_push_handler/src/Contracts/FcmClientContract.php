<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;

interface FcmClientContract
{
    /**
     * @return array{accepted_count:int}
     */
    public function send(PushMessage $message, int $audienceSize): array;
}
