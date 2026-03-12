<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushAudienceEligibilityAllowAll implements PushAudienceEligibilityContract
{
    /**
     * @param  array<string, mixed>  $audience
     * @param  array<string, mixed>  $context
     */
    public function isEligible(
        Authenticatable $user,
        PushMessage $message,
        array $audience,
        array $context = []
    ): bool {
        return true;
    }
}
