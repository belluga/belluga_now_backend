<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Contracts\EventAttendanceReadContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Events\Models\Tenants\Event;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class EventQueryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_assert_public_visible_throws_domain_exception_for_draft_event(): void
    {
        $service = $this->makeService();
        $event = new Event([
            'publication' => [
                'status' => 'draft',
            ],
        ]);

        $this->expectException(EventNotPubliclyVisibleException::class);
        $this->expectExceptionMessage('Event not found.');

        $service->assertPublicVisible($event);
    }

    public function test_assert_public_visible_throws_domain_exception_for_future_publication(): void
    {
        $service = $this->makeService();
        $event = new Event([
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->addHour(),
            ],
        ]);

        $this->expectException(EventNotPubliclyVisibleException::class);
        $this->expectExceptionMessage('Event not found.');

        $service->assertPublicVisible($event);
    }

    private function makeService(): EventQueryService
    {
        return new EventQueryService(
            Mockery::mock(EventProfileResolverContract::class),
            Mockery::mock(EventRadiusSettingsContract::class),
            Mockery::mock(EventCapabilitySettingsContract::class),
            Mockery::mock(EventAttendanceReadContract::class),
            Mockery::mock(EventTaxonomySnapshotResolverContract::class),
        );
    }
}
