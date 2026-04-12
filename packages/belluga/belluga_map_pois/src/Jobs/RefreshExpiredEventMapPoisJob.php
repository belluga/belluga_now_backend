<?php

declare(strict_types=1);

namespace Belluga\MapPois\Jobs;

use Belluga\MapPois\Application\MapPoiOrphanCleanupService;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Jobs\TenantAware;

class RefreshExpiredEventMapPoisJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 20, 40];
    }

    public function handle(
        MapPoiOrphanCleanupService $orphanCleanupService,
        MapPoiProjectionService $projectionService,
        MapPoiSourceReaderContract $sourceReader,
    ): void {
        $orphanCleanupService->cleanup(['event']);
        $this->refreshExpiredEventPois($projectionService, $sourceReader);
    }

    private function refreshExpiredEventPois(
        MapPoiProjectionService $projectionService,
        MapPoiSourceReaderContract $sourceReader,
    ): void {
        $now = Carbon::now();

        MapPoi::query()
            ->where('ref_type', 'event')
            ->where('is_active', true)
            ->whereNotNull('active_window_end_at')
            ->where('active_window_end_at', '<=', $now)
            ->orderBy('active_window_end_at')
            ->cursor()
            ->each(function (MapPoi $poi) use ($projectionService, $sourceReader): void {
                $eventId = trim((string) ($poi->ref_id ?? ''));
                if ($eventId === '') {
                    return;
                }

                $event = $sourceReader->findEventById($eventId);
                if (! $event) {
                    $projectionService->deleteByRef('event', $eventId);

                    return;
                }

                $projectionService->upsertFromEvent($event);
            });
    }
}
