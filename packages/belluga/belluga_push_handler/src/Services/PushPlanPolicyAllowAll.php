<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class PushPlanPolicyAllowAll implements PushPlanPolicyContract, PushPlanPolicyDecisionContract
{
    public function canSend(string $accountId, PushMessage $message, int $audienceSize): bool
    {
        return true;
    }

    public function quotaDecision(string $accountId, PushMessage $message, int $audienceSize): array
    {
        return [
            'allowed' => true,
            'limit' => null,
            'current_used' => null,
            'requested' => $audienceSize,
            'remaining_after' => null,
            'period' => null,
            'reason' => null,
        ];
    }
}
