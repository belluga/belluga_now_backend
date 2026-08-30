<?php

declare(strict_types=1);

namespace Belluga\MapPois\Application;

use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;

class ExpiredEventMapPoiRefreshService
{
    public function __construct(
        private readonly MapPoiProjectionService $projectionService,
        private readonly MapPoiSourceReaderContract $sourceReader,
    ) {}

    public function refreshExpired(?Carbon $now = null): void
    {
        $now ??= Carbon::now();

        MapPoi::query()
            ->where('ref_type', 'event')
            ->where('is_active', true)
            ->whereNotNull('active_window_end_at')
            ->where('active_window_end_at', '<=', $now)
            ->orderBy('active_window_end_at')
            ->orderBy('_id')
            ->cursor()
            ->each(function (MapPoi $poi): void {
                $eventId = trim((string) ($poi->ref_id ?? ''));
                if ($eventId === '') {
                    return;
                }

                $event = $this->sourceReader->findEventById($eventId);
                if (! $event) {
                    $this->projectionService->deleteByRef('event', $eventId);

                    return;
                }

                $this->projectionService->upsertFromEvent($event);
            });
    }
}
