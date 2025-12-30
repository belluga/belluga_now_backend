<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;

interface PushPlanPolicyContract
{
    public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool;
}
