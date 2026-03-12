<?php

declare(strict_types=1);

namespace Belluga\MapPois\Listeners\EventsPackage;

use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Domain\Events\EventCreated;

class SyncMapPoiOnEventCreated
{
    public function __construct(
        private readonly EventProjectionSyncContract $projectionSync
    ) {}

    public function handle(EventCreated $event): void
    {
        $this->projectionSync->upsertEvent($event->eventId);
    }
}
