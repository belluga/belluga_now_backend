<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class PushPlanPolicyAllowAll implements PushPlanPolicyContract
{
    public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
    {
        return true;
    }
}
