<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\EventAudienceResolverContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class PushMessageAudienceService
{
    public function __construct(
        private readonly EventAudienceResolverContract $eventAudienceResolver
    ) {
    }

    public function userHasAccess(PushMessage $message, string $userId): bool
    {
        $audience = $message->audience ?? [];
        $type = $audience['type'] ?? 'all';

        if ($type === 'all') {
            return true;
        }

        if ($type === 'users') {
            $userIds = $audience['user_ids'] ?? [];
            return in_array($userId, $userIds, true);
        }

        if ($type === 'event') {
            $eventId = (string) ($audience['event_id'] ?? '');
            $qualifier = (string) ($audience['event_qualifier'] ?? '');
            if ($eventId === '' || $qualifier === '') {
                return false;
            }
            return $this->eventAudienceResolver->userHasAccess($userId, $eventId, $qualifier);
        }

        return false;
    }

    public function audienceSize(PushMessage $message): int
    {
        $audience = $message->audience ?? [];
        $type = $audience['type'] ?? 'all';

        if ($type === 'users') {
            return count($audience['user_ids'] ?? []);
        }

        return 0;
    }
}
