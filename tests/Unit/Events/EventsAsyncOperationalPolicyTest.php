<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;
use Tests\TestCase;

class EventsAsyncOperationalPolicyTest extends TestCase
{
    public function test_events_async_jobs_use_five_attempts_with_exponential_backoff(): void
    {
        $publishJob = new PublishScheduledEventsJob;
        $upsertJob = new UpsertMapPoiFromEventJob('event-id');
        $deleteJob = new DeleteMapPoiByRefJob('event', 'event-id');

        $this->assertSame(5, $publishJob->tries);
        $this->assertSame([5, 10, 20, 40], $publishJob->backoff());

        $this->assertSame(5, $upsertJob->tries);
        $this->assertSame([5, 10, 20, 40], $upsertJob->backoff());

        $this->assertSame(5, $deleteJob->tries);
        $this->assertSame([5, 10, 20, 40], $deleteJob->backoff());
    }
}
