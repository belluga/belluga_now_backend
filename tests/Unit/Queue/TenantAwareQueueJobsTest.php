<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Jobs\Auth\DeliverPhoneOtpWebhookJob;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Favorites\Jobs\RebuildFavoriteSnapshotJob;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromStaticAssetJob;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Spatie\Multitenancy\Jobs\TenantAware;
use Tests\TestCase;

class TenantAwareQueueJobsTest extends TestCase
{
    public function test_all_queue_jobs_are_explicitly_tenant_aware(): void
    {
        foreach ($this->tenantAwareJobClasses() as $jobClass) {
            $this->assertTrue(
                is_subclass_of($jobClass, TenantAware::class),
                sprintf('Queue job [%s] must implement %s.', $jobClass, TenantAware::class),
            );
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function tenantAwareJobClasses(): array
    {
        return [
            DeliverPhoneOtpWebhookJob::class,
            DeliverTelemetryEventJob::class,
            PublishScheduledEventsJob::class,
            RebuildFavoriteSnapshotJob::class,
            DeleteMapPoiByRefJob::class,
            RefreshExpiredEventMapPoisJob::class,
            UpsertMapPoiFromAccountProfileJob::class,
            UpsertMapPoiFromEventJob::class,
            UpsertMapPoiFromStaticAssetJob::class,
            SendPushMessageJob::class,
        ];
    }
}
