<?php

declare(strict_types=1);

namespace App\Listeners\EventsPackage;

use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Domain\Events\EventDeleted;

class SyncMapPoiOnEventDeleted
{
    public function __construct(
        private readonly EventProjectionSyncContract $projectionSync
    ) {
    }

    public function handle(EventDeleted $event): void
    {
        $this->projectionSync->deleteEvent($event->eventId);
    }
}
