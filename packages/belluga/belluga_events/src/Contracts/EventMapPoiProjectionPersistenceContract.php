<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Models\Tenants\Event;

/**
 * Events owns when its aggregate transaction persists or removes its MapPoi
 * projection; the host binds the MapPoi implementation.
 */
interface EventMapPoiProjectionPersistenceContract
{
    public function persistForLiveEvent(EventTransactionContext $context, Event $event): void;

    public function deleteForEvent(EventTransactionContext $context, string $eventId): void;
}
