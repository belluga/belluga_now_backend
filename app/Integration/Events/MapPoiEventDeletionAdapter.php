<?php

declare(strict_types=1);

namespace App\Integration\Events;

use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Contracts\EventMapPoiDeletionContract;
use Belluga\MapPois\Application\MapPoiProjectionService;

final class MapPoiEventDeletionAdapter implements EventMapPoiDeletionContract
{
    public function __construct(private readonly MapPoiProjectionService $mapPois) {}

    public function deleteForEvent(EventTransactionContext $context, string $eventId): void
    {
        $this->mapPois->deleteByRefsWithinTransaction(
            $context->database(),
            $context->session(),
            'event',
            [$eventId],
        );
    }
}
