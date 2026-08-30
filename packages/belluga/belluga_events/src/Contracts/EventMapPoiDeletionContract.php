<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

use Belluga\Events\Application\Transactions\EventTransactionContext;

interface EventMapPoiDeletionContract
{
    public function deleteForEvent(EventTransactionContext $context, string $eventId): void;
}
