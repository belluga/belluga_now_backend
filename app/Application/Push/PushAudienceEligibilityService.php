<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Application\Events\AttendanceCommitmentService;
use App\Models\Tenants\AccountUser;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Contracts\Auth\Authenticatable;

class PushAudienceEligibilityService implements PushAudienceEligibilityContract
{
    public function __construct(
        private readonly AttendanceCommitmentService $attendance,
    ) {}

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

        $type = trim((string) ($audience['type'] ?? ''));
        $userId = (string) $user->_id;

        if ($type === 'users') {
            $ids = is_array($audience['user_ids'] ?? null) ? $audience['user_ids'] : [];

            return in_array($userId, $ids, true);
        }

        if ($type === 'all_users') {
            return true;
        }

        if ($type === 'favorite_account_profile') {
            $accountProfileId = trim((string) ($audience['account_profile_id'] ?? ''));
            if ($accountProfileId === '') {
                return false;
            }

            return FavoriteEdge::query()
                ->where('owner_user_id', $userId)
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', $accountProfileId)
                ->exists();
        }

        if ($type === 'event_confirmed') {
            $eventId = trim((string) ($audience['event_id'] ?? ''));
            if ($eventId === '') {
                return false;
            }

            return $this->attendance->hasConfirmedEvent($userId, $eventId);
        }

        return false;
    }
}
