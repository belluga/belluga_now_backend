<?php

declare(strict_types=1);

namespace Belluga\Events\Jobs;

use Belluga\Events\Application\Events\EventPublicationManagementService;
use Belluga\Events\Application\Events\ScheduledEventPublicationSelectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Jobs\TenantAware;

class PublishScheduledEventsJob implements ShouldQueue, TenantAware
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
        ScheduledEventPublicationSelectionService $selectionService,
        EventPublicationManagementService $managementService,
    ): void {
        $now = Carbon::now();

        foreach ($selectionService->dueEventIds($now) as $eventId) {
            $managementService->publishScheduledEventIfDue($eventId, $now);
        }
    }
}
