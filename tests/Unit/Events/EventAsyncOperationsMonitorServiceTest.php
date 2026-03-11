<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Belluga\Events\Application\Operations\EventAsyncOperationsMonitorService;
use Belluga\Events\Contracts\EventAsyncQueueMetricsProviderContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EventAsyncOperationsMonitorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(EventAsyncOperationsMonitorService::CACHE_BREACH_COUNT_KEY);
        Cache::forget(EventAsyncOperationsMonitorService::CACHE_ALERT_ACTIVE_KEY);
    }

    public function test_it_triggers_staleness_alert_after_five_consecutive_breaches(): void
    {
        Log::spy();

        $provider = new FakeEventAsyncQueueMetricsProvider([70, 65, 62]);
        $service = new EventAsyncOperationsMonitorService($provider);

        for ($i = 0; $i < 5; $i++) {
            $service->evaluate();
        }

        $this->assertSame(
            5,
            Cache::get(EventAsyncOperationsMonitorService::CACHE_BREACH_COUNT_KEY)
        );
        $this->assertTrue(
            (bool) Cache::get(EventAsyncOperationsMonitorService::CACHE_ALERT_ACTIVE_KEY, false)
        );

        Log::shouldHaveReceived('critical')->with(
            'events_async_queue_staleness_alert',
            \Mockery::type('array')
        )->once();
    }

    public function test_it_clears_alert_state_when_queue_recovers(): void
    {
        Log::spy();

        $provider = new FakeEventAsyncQueueMetricsProvider([70, 64, 63]);
        $service = new EventAsyncOperationsMonitorService($provider);

        for ($i = 0; $i < 5; $i++) {
            $service->evaluate();
        }

        $provider->setAges([12, 10]);
        $service->evaluate();

        $this->assertNull(Cache::get(EventAsyncOperationsMonitorService::CACHE_BREACH_COUNT_KEY));
        $this->assertFalse(
            (bool) Cache::get(EventAsyncOperationsMonitorService::CACHE_ALERT_ACTIVE_KEY, false)
        );

        Log::shouldHaveReceived('info')->with(
            'events_async_queue_staleness_recovered',
            \Mockery::type('array')
        )->once();
    }
}

final class FakeEventAsyncQueueMetricsProvider implements EventAsyncQueueMetricsProviderContract
{
    /**
     * @param  array<int, int>  $ages
     */
    public function __construct(private array $ages) {}

    /**
     * @return array<int, int>
     */
    public function pendingAgesInSeconds(): array
    {
        return $this->ages;
    }

    /**
     * @param  array<int, int>  $ages
     */
    public function setAges(array $ages): void
    {
        $this->ages = $ages;
    }
}
