<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class PushAudienceEligibilityAllowAll implements PushAudienceEligibilityContract
{
    /**
     * @param array<string, mixed> $audience
     * @param array<string, mixed> $context
     */
    public function isEligible(
        AccountUser $user,
        PushMessage $message,
        array $audience,
        array $context = []
    ): bool {
        return true;
    }
}
