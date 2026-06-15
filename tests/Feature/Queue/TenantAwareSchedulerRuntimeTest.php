<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Events\Models\Tenants\Event;
use Belluga\MapPois\Jobs\CleanupOrphanedMapPoisJob;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
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

        $primaryFixture = $this->createProfileCleanupScheduleFixture($primaryTenant, 'primary');
        $secondaryFixture = $this->createProfileCleanupScheduleFixture($secondaryTenant, 'secondary');

        $this->runScheduledCallbackByName('map_pois:cleanup_orphaned');

        $this->assertProfileCleanupScheduleFixture($primaryTenant, $primaryFixture);
        $this->assertProfileCleanupScheduleFixture($secondaryTenant, $secondaryFixture);
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

            $tenantId = self::resolveTenantIdForProcessedJob($payload);
            if ($tenantId !== '') {
                $tenantIds[] = $tenantId;
            }
        });

        $runner();

        return array_values(array_unique($tenantIds));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function resolveTenantIdForProcessedJob(array $payload): string
    {
        $contextKey = (string) config('multitenancy.current_tenant_context_key', 'tenantId');

        $contextTenantId = trim((string) Context::get($contextKey, ''));
        if ($contextTenantId !== '') {
            return $contextTenantId;
        }

        $serializedTenantId = $payload['illuminate:log:context']['data'][$contextKey] ?? null;
        if (is_string($serializedTenantId) && $serializedTenantId !== '') {
            try {
                $hydratedTenantId = trim((string) unserialize($serializedTenantId));
                if ($hydratedTenantId !== '') {
                    return $hydratedTenantId;
                }
            } catch (\Throwable) {
                // Ignore malformed context payload and continue to the final fallback.
            }
        }

        return trim((string) (Tenant::current()?->getAttribute('_id') ?? ''));
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

    /**
     * @return array{live_poi_id: string, recent_deleted_poi_id: string, old_deleted_poi_id: string}
     */
    private function createProfileCleanupScheduleFixture(Tenant $tenant, string $suffix): array
    {
        $tenant->makeCurrent();

        try {
            $liveProfile = $this->createAccountProfileFixture(sprintf('%s-live', $suffix));
            $recentDeletedProfile = $this->createAccountProfileFixture(sprintf('%s-recent', $suffix));
            $oldDeletedProfile = $this->createAccountProfileFixture(sprintf('%s-old', $suffix));

            $livePoi = $this->createMapPoiFixture((string) $liveProfile->getAttribute('_id'), sprintf('%s-live-poi', $suffix));
            $recentDeletedPoi = $this->createMapPoiFixture((string) $recentDeletedProfile->getAttribute('_id'), sprintf('%s-recent-poi', $suffix));
            $oldDeletedPoi = $this->createMapPoiFixture((string) $oldDeletedProfile->getAttribute('_id'), sprintf('%s-old-poi', $suffix));

            $recentDeletedProfile->delete();
            $oldDeletedProfile->delete();
            $oldDeletedProfile->forceFill([
                'deleted_at' => Carbon::now()->subHours(2),
            ])->save();

            return [
                'live_poi_id' => (string) $livePoi->getAttribute('_id'),
                'recent_deleted_poi_id' => (string) $recentDeletedPoi->getAttribute('_id'),
                'old_deleted_poi_id' => (string) $oldDeletedPoi->getAttribute('_id'),
            ];
        } finally {
            $tenant->forgetCurrent();
        }
    }

    /**
     * @param  array{live_poi_id: string, recent_deleted_poi_id: string, old_deleted_poi_id: string}  $fixture
     */
    private function assertProfileCleanupScheduleFixture(Tenant $tenant, array $fixture): void
    {
        $tenant->makeCurrent();

        try {
            $this->assertTrue(MapPoi::query()->where('_id', $fixture['live_poi_id'])->exists());
            $this->assertFalse(MapPoi::query()->where('_id', $fixture['recent_deleted_poi_id'])->exists());
            $this->assertTrue(MapPoi::query()->where('_id', $fixture['old_deleted_poi_id'])->exists());
        } finally {
            $tenant->forgetCurrent();
        }
    }

    private function createAccountProfileFixture(string $suffix): AccountProfile
    {
        $account = Account::query()->create([
            'name' => sprintf('scheduler-runtime-%s-%s', $suffix, Str::uuid()->toString()),
            'document' => strtoupper(substr(str_replace('-', '', Str::uuid()->toString()), 0, 14)),
        ]);

        return AccountProfile::query()->create([
            'account_id' => (string) $account->getAttribute('_id'),
            'profile_type' => 'artist',
            'display_name' => sprintf('Scheduler Runtime %s', $suffix),
            'is_active' => true,
        ]);
    }

    private function createMapPoiFixture(string $profileId, string $name): MapPoi
    {
        return MapPoi::query()->create([
            'ref_type' => 'account_profile',
            'ref_id' => $profileId,
            'name' => $name,
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'is_active' => true,
        ]);
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
