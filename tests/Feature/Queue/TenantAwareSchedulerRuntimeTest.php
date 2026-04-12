<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Models\Landlord\Tenant;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Events\Models\Tenants\Event;
use Belluga\MapPois\Jobs\CleanupOrphanedMapPoisJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Tests\TestCaseAuthenticated;

class TenantAwareSchedulerRuntimeTest extends TestCaseAuthenticated
{
    public function test_publish_scheduled_dispatches_with_expected_tenant_payload_and_updates_each_tenant(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryEventId = $this->createScheduledPublishEvent($primaryTenant, 'primary');
        $secondaryEventId = $this->createScheduledPublishEvent($secondaryTenant, 'secondary');

        $processedTenantIds = $this->captureProcessedTenantIdsForJob(
            PublishScheduledEventsJob::class,
            fn () => $this->runScheduledCallbackByName('events:publication:publish_scheduled')
        );

        $this->assertContains((string) $primaryTenant->getAttribute('_id'), $processedTenantIds);
        $this->assertContains((string) $secondaryTenant->getAttribute('_id'), $processedTenantIds);

        $this->assertPublishedStatus($primaryTenant, $primaryEventId);
        $this->assertPublishedStatus($secondaryTenant, $secondaryEventId);
    }

    public function test_map_poi_orphan_cleanup_schedule_dispatches_expected_job_payload_for_each_tenant(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $processedJobs = $this->captureProcessedTenantJobsForJob(
            CleanupOrphanedMapPoisJob::class,
            fn () => $this->runScheduledCallbackByName('map_pois:cleanup_orphaned')
        );

        $this->assertCount(2, $processedJobs);
        $this->assertEqualsCanonicalizing(
            [
                (string) $primaryTenant->getAttribute('_id'),
                (string) $secondaryTenant->getAttribute('_id'),
            ],
            array_column($processedJobs, 'tenant_id')
        );

        foreach ($processedJobs as $processedJob) {
            $this->assertSame(['account_profile', 'static'], $processedJob['job']->refTypes());
            $this->assertSame(60, $processedJob['job']->deletedSinceMinutes());
        }
    }

    private function runScheduledCallbackByName(string $name): void
    {
        $schedule = $this->app->make(Schedule::class);

        $scheduledEvent = collect($schedule->events())
            ->first(static fn (object $event): bool => method_exists($event, 'getSummaryForDisplay')
                && $event->getSummaryForDisplay() === $name);

        $this->assertNotNull($scheduledEvent, sprintf('Scheduled callback [%s] was not found.', $name));

        $scheduledEvent->run($this->app);
    }

    /**
     * @return array<int, string>
     */
    private function captureProcessedTenantIdsForJob(string $jobClass, callable $runner): array
    {
        $tenantIds = [];

        $this->app['events']->listen(JobProcessing::class, static function (JobProcessing $event) use (&$tenantIds, $jobClass): void {
            $payload = $event->job->payload();
            $displayName = (string) ($payload['displayName'] ?? '');
            $commandName = (string) ($payload['data']['commandName'] ?? '');

            if ($displayName !== $jobClass && $commandName !== $jobClass) {
                return;
            }

            $tenantId = (string) (Tenant::current()?->getAttribute('_id') ?? '');
            if ($tenantId !== '') {
                $tenantIds[] = $tenantId;
            }
        });

        $runner();

        return array_values(array_unique($tenantIds));
    }

    /**
     * @return array<int, array{tenant_id: string, job: CleanupOrphanedMapPoisJob}>
     */
    private function captureProcessedTenantJobsForJob(string $jobClass, callable $runner): array
    {
        $processedJobs = [];

        $this->app['events']->listen(JobProcessing::class, static function (JobProcessing $event) use (&$processedJobs, $jobClass): void {
            $payload = $event->job->payload();
            $displayName = (string) ($payload['displayName'] ?? '');
            $commandName = (string) ($payload['data']['commandName'] ?? '');

            if ($displayName !== $jobClass && $commandName !== $jobClass) {
                return;
            }

            $tenantId = (string) (Tenant::current()?->getAttribute('_id') ?? '');
            if ($tenantId === '') {
                return;
            }

            $command = $payload['data']['command'] ?? null;
            if (! is_string($command)) {
                return;
            }

            $job = unserialize($command);
            if (! $job instanceof $jobClass) {
                return;
            }

            $processedJobs[] = [
                'tenant_id' => $tenantId,
                'job' => $job,
            ];
        });

        $runner();

        return $processedJobs;
    }

    private function createScheduledPublishEvent(Tenant $tenant, string $suffix): string
    {
        $tenant->makeCurrent();

        try {
            $event = Event::query()->create([
                'title' => sprintf('scheduler-publish-%s-%s', $suffix, Carbon::now()->format('Uu')),
                'publication' => [
                    'status' => 'publish_scheduled',
                    'publish_at' => Carbon::now()->subMinute(),
                ],
            ]);

            return (string) $event->getAttribute('_id');
        } finally {
            $tenant->forgetCurrent();
        }
    }

    private function assertPublishedStatus(Tenant $tenant, string $eventId): void
    {
        $tenant->makeCurrent();

        try {
            $event = Event::query()->findOrFail($eventId);
            $publication = is_array($event->publication) ? $event->publication : (array) $event->publication;

            $this->assertSame('published', (string) ($publication['status'] ?? ''));
        } finally {
            $tenant->forgetCurrent();
        }
    }

    private function primaryTenant(): Tenant
    {
        return Tenant::query()
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function secondaryTenant(): Tenant
    {
        $primaryTenant = $this->primaryTenant();

        $secondary = Tenant::query()
            ->where('_id', '!=', $primaryTenant->getAttribute('_id'))
            ->first();

        if ($secondary) {
            return $secondary;
        }

        return Tenant::create([
            'name' => 'Scheduler Runtime Secondary',
            'subdomain' => 'scheduler-runtime-secondary',
            'app_domains' => ['com.scheduler.runtime.secondary'],
        ]);
    }
}
