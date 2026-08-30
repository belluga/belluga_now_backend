<?php

declare(strict_types=1);

namespace App\Listeners\Events;

use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;

class SyncMapPoiOnEventUpdated
{
    public function handle(EventUpdated $event): void
    {
        UpsertMapPoiFromEventJob::dispatch($event->eventId);
    }
}
