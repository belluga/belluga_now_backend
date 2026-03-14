<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Belluga\Events\Application\Operations\QueueEventAsyncMetricsProvider;
use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Tests\TestCase;

class QueueEventAsyncMetricsProviderTest extends TestCase
{
    public function test_it_marks_missing_queue_connection_config_as_unavailable(): void
    {
        config()->set('queue.default', 'missing');
        config()->set('queue.connections', []);

        $snapshot = $this->makeProvider()->snapshot();

        $this->assertTrue($snapshot->isUnavailable());
        $this->assertSame('queue_connection_config_missing', $snapshot->reason());
    }

    public function test_it_marks_unsupported_queue_driver_as_unavailable(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
        ]);

        $snapshot = $this->makeProvider()->snapshot();

        $this->assertTrue($snapshot->isUnavailable());
        $this->assertSame('queue_metrics_unsupported_for_connection', $snapshot->reason());
    }

    private function makeProvider(): QueueEventAsyncMetricsProvider
    {
        return new QueueEventAsyncMetricsProvider(new class implements EventAsyncJobSignaturesContract
        {
            public function signatures(): array
            {
                return ['Belluga\\Events\\Jobs\\FakeJob'];
            }
        });
    }
}
