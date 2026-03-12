<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushMessageAudienceService
{
    public function __construct(
        private readonly PushAudienceEligibilityContract $eligibilityContract
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function isEligible(Authenticatable $user, PushMessage $message, array $context = []): bool
    {
        $audience = $message->audience ?? [];

        return $this->eligibilityContract->isEligible($user, $message, $audience, $context);
    }

    public function audienceSize(PushMessage $message): int
    {
        $audience = $message->audience ?? [];

        if (($audience['type'] ?? null) === 'users') {
            return count($audience['user_ids'] ?? []);
        }

        return 0;
    }
}
