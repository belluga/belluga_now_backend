<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\MapPois\MapPoiProjectionService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class PublishScheduledEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $now = Carbon::now();

        Tenant::query()
            ->get()
            ->each(function (Tenant $tenant) use ($now, $projectionService): void {
                $tenant->makeCurrent();

                $events = Event::query()
                    ->where('publication.status', 'publish_scheduled')
                    ->where('publication.publish_at', '<=', $now)
                    ->get();

                if ($events->isNotEmpty()) {
                    Event::query()
                        ->where('publication.status', 'publish_scheduled')
                        ->where('publication.publish_at', '<=', $now)
                        ->update([
                            'publication.status' => 'published',
                        ]);

                    foreach ($events as $event) {
                        $publication = is_array($event->publication) ? $event->publication : (array) $event->publication;
                        $publication['status'] = 'published';
                        $event->publication = $publication;
                        $projectionService->upsertFromEvent($event);
                    }
                }

                $tenant->forgetCurrent();
            });
    }
}
