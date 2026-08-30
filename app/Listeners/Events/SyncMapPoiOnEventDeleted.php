<?php

declare(strict_types=1);

namespace App\Listeners\Events;

use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;

class SyncMapPoiOnEventDeleted
{
    public function handle(EventDeleted $event): void
    {
        DeleteMapPoiByRefJob::dispatch('event', $event->eventId);
    }
}
