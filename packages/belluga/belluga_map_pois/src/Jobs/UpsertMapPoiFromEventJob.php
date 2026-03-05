<?php

declare(strict_types=1);

namespace Belluga\MapPois\Jobs;

use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpsertMapPoiFromEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(private readonly string $eventId)
    {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 20, 40];
    }

    public function handle(
        MapPoiProjectionService $projectionService,
        MapPoiSourceReaderContract $sourceReader,
    ): void
    {
        $event = $sourceReader->findEventById($this->eventId);

        if (! $event) {
            $projectionService->deleteByRef('event', $this->eventId);
            return;
        }

        $projectionService->upsertFromEvent($event);
    }
}
