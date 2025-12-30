<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface EventAudienceResolverContract
{
    public function userHasAccess(string $userId, string $eventId, string $qualifier): bool;
}
