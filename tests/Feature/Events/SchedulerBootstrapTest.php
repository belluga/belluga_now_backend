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

    public function test_console_schedule_registers_publish_job_via_class_string(): void
    {
        $routesConsole = file_get_contents(base_path('routes/console.php'));

        $this->assertNotFalse($routesConsole);
        $this->assertStringContainsString(
            'Schedule::job(PublishScheduledEventsJob::class)->hourly();',
            $routesConsole
        );
        $this->assertStringNotContainsString(
            'Schedule::job(new PublishScheduledEventsJob())',
            $routesConsole
        );
    }
}
