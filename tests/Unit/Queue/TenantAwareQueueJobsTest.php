<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Jobs\Auth\DeliverPhoneOtpWebhookJob;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromStaticAssetJob;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Spatie\Multitenancy\Jobs\TenantAware;
use Tests\TestCase;
use Tests\Traits\EnsuresSystemInitialization;

class TenantAwareQueueJobsTest extends TestCase
{
    use EnsuresSystemInitialization;

    public function test_all_queue_jobs_are_explicitly_tenant_aware(): void
    {
        foreach ($this->tenantAwareJobClasses() as $jobClass) {
            $this->assertTrue(
                is_subclass_of($jobClass, TenantAware::class),
                sprintf('Queue job [%s] must implement %s.', $jobClass, TenantAware::class),
            );
        }
    }

    public function test_representative_tenant_aware_dispatches_store_jobs_in_shared_queue_storage_only(): void
    {
        $this->ensureSystemInitialized();
        $this->useMongoQueueRuntimeForTest();
        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);

        DeliverTelemetryEventJob::dispatch(
            ['type' => 'queue_guardrail_probe', 'tenant_id' => 'tenant-probe'],
            []
        );
        SendPushMessageJob::dispatch('queue-guardrail-message', 'tenant', null);

        $this->assertSame(2, $this->queueJobCount('mongodb'));
        $this->assertSame(0, $this->queueJobCount('tenant'));
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
            DeleteMapPoiByRefJob::class,
            RefreshExpiredEventMapPoisJob::class,
            UpsertMapPoiFromAccountProfileJob::class,
            UpsertMapPoiFromEventJob::class,
            UpsertMapPoiFromStaticAssetJob::class,
            SendPushMessageJob::class,
        ];
    }

    private function useMongoQueueRuntimeForTest(): void
    {
        config([
            'queue.default' => 'mongodb',
            'queue.connections.mongodb.connection' => 'mongodb',
            'queue.connections.mongodb.collection' => 'jobs',
            'queue.connections.mongodb.queue' => 'default',
            'queue.failed.driver' => 'null',
        ]);

        app('db')
            ->connection('mongodb')
            ->table('jobs')
            ->delete();

        app('db')
            ->connection('tenant')
            ->table('jobs')
            ->delete();
    }

    private function queueJobCount(string $connection): int
    {
        return (int) app('db')
            ->connection($connection)
            ->table('jobs')
            ->count();
    }
}
