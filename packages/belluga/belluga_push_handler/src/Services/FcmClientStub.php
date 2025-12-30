<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class FcmClientStub implements FcmClientContract
{
    public function send(PushMessage $message, int $audienceSize): array
    {
        return [
            'accepted_count' => $audienceSize,
        ];
    }
}
