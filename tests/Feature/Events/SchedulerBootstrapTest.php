<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
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

        $this->artisan('schedule:list')->assertExitCode(0);
    }

    public function test_console_schedule_registers_current_event_dispatches_and_keeps_ticketing_jobs_removed(): void
    {
        $routesConsole = file_get_contents(base_path('routes/console.php'));

        $this->assertNotFalse($routesConsole);
        $this->assertStringContainsString(
            "->name('events:publication:publish_scheduled')",
            $routesConsole
        );
        $this->assertStringContainsString('PublishScheduledEventsJob::dispatch();', $routesConsole);
        $this->assertStringContainsString("->name('events:async:monitor')", $routesConsole);
        $this->assertStringContainsString("->name('events:occurrences:reconcile')", $routesConsole);
        $this->assertStringContainsString("->name('events:map_pois:refresh_expired')", $routesConsole);
        $this->assertStringContainsString('RefreshExpiredEventMapPoisJob::dispatch();', $routesConsole);
        $this->assertStringNotContainsString('ProcessTicketOutboxJob::dispatch();', $routesConsole);
        $this->assertStringNotContainsString('ExpireIssuedTicketUnitsJob::dispatch();', $routesConsole);
        $this->assertStringNotContainsString('Schedule::job(PublishScheduledEventsJob::class)->hourly();', $routesConsole);
        $this->assertStringNotContainsString('Schedule::job(new ProcessTicketOutboxJob)->everyMinute();', $routesConsole);
    }
}
