<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Jobs\MapPois\DeleteMapPoiByRefJob;
use App\Jobs\MapPois\UpsertMapPoiFromEventJob;
use Belluga\Events\Contracts\EventProjectionSyncContract;

class EventMapPoiProjectionSyncAdapter implements EventProjectionSyncContract
{
    public function upsertEvent(string $eventId): void
    {
        UpsertMapPoiFromEventJob::dispatch($eventId);
    }

    public function deleteEvent(string $eventId): void
    {
        DeleteMapPoiByRefJob::dispatch('event', $eventId);
    }
}
