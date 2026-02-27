<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Tests\TestCase;

class EventsPackageDecouplingTest extends TestCase
{
    public function testPackageAsyncOperationalServicesDoNotReferenceHostMapJobs(): void
    {
        $services = [
            base_path('packages/belluga/belluga_events/src/Application/Operations/EventDlqAlertService.php'),
            base_path('packages/belluga/belluga_events/src/Application/Operations/QueueEventAsyncMetricsProvider.php'),
        ];

        foreach ($services as $servicePath) {
            $contents = file_get_contents($servicePath);
            $this->assertIsString($contents, "Expected readable service file [{$servicePath}].");
            $this->assertStringNotContainsString(
                'App\\Jobs\\MapPois\\',
                $contents,
                "Package service must not hardcode host map jobs [{$servicePath}]."
            );
        }
    }
}

