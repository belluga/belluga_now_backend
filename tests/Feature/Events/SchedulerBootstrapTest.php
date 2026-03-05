<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Tests\TestCase;

class SchedulerBootstrapTest extends TestCase
{
    public function testScheduleListBootstrapsWithoutClassResolutionErrors(): void
    {
        $this->assertTrue(
            class_exists(PublishScheduledEventsJob::class),
            'PublishScheduledEventsJob must be autoload-resolvable during console bootstrap.'
        );

        $this->artisan('schedule:list')->assertExitCode(0);
    }

    public function testConsoleScheduleRegistersPublishJobViaClassString(): void
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

