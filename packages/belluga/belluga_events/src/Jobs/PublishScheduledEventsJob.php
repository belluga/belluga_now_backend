<?php

declare(strict_types=1);

namespace Belluga\Events\Jobs;

use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class PublishScheduledEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TenantExecutionContextContract $tenantExecutionContext, Dispatcher $events): void
    {
        $now = Carbon::now();

        $tenantExecutionContext->runForEachTenant(function () use ($now, $events): void {
            $scheduledEvents = Event::query()
                ->where('publication.status', 'publish_scheduled')
                ->where('publication.publish_at', '<=', $now)
                ->get();

            if ($scheduledEvents->isEmpty()) {
                return;
            }

            Event::query()
                ->where('publication.status', 'publish_scheduled')
                ->where('publication.publish_at', '<=', $now)
                ->update([
                    'publication.status' => 'published',
                ]);

            foreach ($scheduledEvents as $scheduledEvent) {
                $events->dispatch(new EventUpdated((string) $scheduledEvent->_id));
            }
        });
    }
}
