<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

interface PushAudienceEligibilityContract
{
    /**
     * @param array<string, mixed> $audience
     * @param array<string, mixed> $context
     */
    public function isEligible(
        Authenticatable $user,
        PushMessage $message,
        array $audience,
        array $context = []
    ): bool;
}
