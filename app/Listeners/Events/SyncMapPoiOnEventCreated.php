<?php

declare(strict_types=1);

namespace App\Listeners\Events;

use Belluga\Events\Domain\Events\EventCreated;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;

class SyncMapPoiOnEventCreated
{
    public function handle(EventCreated $event): void
    {
        UpsertMapPoiFromEventJob::dispatch($event->eventId);
    }
}
