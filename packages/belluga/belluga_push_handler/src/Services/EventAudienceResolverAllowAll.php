<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\EventAudienceResolverContract;

class EventAudienceResolverAllowAll implements EventAudienceResolverContract
{
    public function userHasAccess(string $userId, string $eventId, string $qualifier): bool
    {
        return true;
    }
}
