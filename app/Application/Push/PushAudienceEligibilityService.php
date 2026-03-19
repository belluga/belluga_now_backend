<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushAudienceEligibilityService implements PushAudienceEligibilityContract
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
        if (! $user instanceof AccountUser) {
            return false;
        }

        $type = $audience['type'] ?? 'all';

        if ($type === 'users') {
            $ids = $audience['user_ids'] ?? [];

            return in_array((string) $user->_id, $ids, true);
        }

        return true;
    }
}
