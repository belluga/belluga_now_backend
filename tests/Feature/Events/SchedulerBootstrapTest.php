<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Tests\TestCase;

class SchedulerBootstrapTest extends TestCase
{
    public function test_schedule_list_bootstraps_without_class_resolution_errors(): void
    {
        $this->assertTrue(
            class_exists(PublishScheduledEventsJob::class),
            'PublishScheduledEventsJob must be autoload-resolvable during console bootstrap.'
        );

        $this->artisan('schedule:list')->assertExitCode(0);
    }

    public function test_console_schedule_registers_tenant_fan_out_dispatch_for_tenant_jobs(): void
    {
        $routesConsole = file_get_contents(base_path('routes/console.php'));

        $this->assertNotFalse($routesConsole);
        $this->assertStringContainsString(
            "->name('events:publication:publish_scheduled')",
            $routesConsole
        );
        $this->assertStringContainsString('PublishScheduledEventsJob::dispatch();', $routesConsole);
        $this->assertStringContainsString('ProcessTicketOutboxJob::dispatch();', $routesConsole);
        $this->assertStringContainsString('ExpireIssuedTicketUnitsJob::dispatch();', $routesConsole);
        $this->assertStringNotContainsString('Schedule::job(PublishScheduledEventsJob::class)->hourly();', $routesConsole);
        $this->assertStringNotContainsString('Schedule::job(new ProcessTicketOutboxJob)->everyMinute();', $routesConsole);
    }
}
