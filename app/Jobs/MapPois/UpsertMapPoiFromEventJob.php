<?php

declare(strict_types=1);

namespace App\Jobs\MapPois;

use App\Application\MapPois\MapPoiProjectionService;
use App\Models\Tenants\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MongoDB\BSON\ObjectId;

class UpsertMapPoiFromEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $eventId)
    {
    }

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $event = Event::query()->find($this->eventId);
        if (! $event) {
            try {
                $event = Event::query()->find(new ObjectId($this->eventId));
            } catch (\Throwable) {
                // Ignore invalid ObjectId values.
            }
        }

        if (! $event) {
            $projectionService->deleteByRef('event', $this->eventId);
            return;
        }

        $projectionService->upsertFromEvent($event);
    }
}
