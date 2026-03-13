<?php

declare(strict_types=1);

namespace App\Integration\Events;

use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;

class MapPoiEventProjectionSyncAdapter implements EventProjectionSyncContract
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
