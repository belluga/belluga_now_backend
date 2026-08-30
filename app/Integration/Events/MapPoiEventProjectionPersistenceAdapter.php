<?php

declare(strict_types=1);

namespace App\Integration\Events;

use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Contracts\EventMapPoiProjectionPersistenceContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\MapPois\Application\MapPoiProjectionService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MapPoiEventProjectionPersistenceAdapter implements EventMapPoiProjectionPersistenceContract
{
    public function __construct(private readonly MapPoiProjectionService $mapPois) {}

    public function persistForLiveEvent(EventTransactionContext $context, Event $event): void
    {
        $eventId = trim((string) $event->getKey());
        try {
            $eventObjectId = new ObjectId($eventId);
        } catch (\Throwable) {
            throw new NotFoundHttpException;
        }

        $touched = $context->collection('events')->updateOne(
            ['_id' => $eventObjectId, 'deleted_at' => null],
            ['$set' => ['updated_at' => new UTCDateTime((int) now()->getTimestampMs())]],
            $context->rawOptions(),
        );
        if ($touched->getMatchedCount() !== 1) {
            throw new NotFoundHttpException;
        }

        $this->mapPois->upsertFromEventWithinTransaction(
            $event->fresh() ?? $event,
            $context->database(),
            $context->session(),
        );
    }

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
