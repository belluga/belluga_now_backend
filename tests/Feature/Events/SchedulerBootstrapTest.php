<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\MapPois\Jobs\CleanupOrphanedMapPoisJob;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerBootstrapTest extends TestCase
{
    public function test_schedule_list_bootstraps_without_class_resolution_errors(): void
    {
        $this->assertTrue(
            class_exists(PublishScheduledEventsJob::class),
            'PublishScheduledEventsJob must be autoload-resolvable during console bootstrap.'
        );
        $this->assertTrue(
            class_exists(RefreshExpiredEventMapPoisJob::class),
            'RefreshExpiredEventMapPoisJob must be autoload-resolvable during console bootstrap.'
        );
        $this->assertTrue(
            class_exists(CleanupOrphanedMapPoisJob::class),
            'CleanupOrphanedMapPoisJob must be autoload-resolvable during console bootstrap.'
        );

        $this->artisan('schedule:list')->assertExitCode(0);
    }

    public function test_console_schedule_registers_current_event_dispatches_and_keeps_ticketing_jobs_removed(): void
    {
        $eventSummaries = collect($this->app->make(Schedule::class)->events())
            ->map(static fn (object $event): ?string => method_exists($event, 'getSummaryForDisplay')
                ? $event->getSummaryForDisplay()
                : null)
            ->filter()
            ->values()
            ->all();

        $this->assertContains('events:publication:publish_scheduled', $eventSummaries);
        $this->assertContains('events:async:monitor', $eventSummaries);
        $this->assertContains('map_pois:cleanup_orphaned', $eventSummaries);
        $this->assertContains('events:map_pois:refresh_expired', $eventSummaries);
        $this->assertContains('api-security:abuse-signals:prune', $eventSummaries);
        $this->assertNotContains('events:occurrences:reconcile', $eventSummaries);
        $this->assertNotContains('ProcessTicketOutboxJob', $eventSummaries);
        $this->assertNotContains('ExpireIssuedTicketUnitsJob', $eventSummaries);
        $this->assertNotContains(PublishScheduledEventsJob::class, $eventSummaries);

        $commands = $this->app->make(Kernel::class)->all();
        $this->assertArrayHasKey(
            'events:occurrences:repair',
            $commands,
            'Occurrence reconcile must remain manual-only via explicit repair command.'
        );
    }
}
