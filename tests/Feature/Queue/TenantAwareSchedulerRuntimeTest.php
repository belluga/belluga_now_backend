<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Models\Landlord\Tenant;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Ticketing\Jobs\ProcessTicketOutboxJob;
use Belluga\Ticketing\Models\Tenants\TicketOutboxEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    public function test_ticket_outbox_scheduler_dispatches_with_expected_tenant_payload_and_processes_each_tenant(): void
    {
        $primaryTenant = $this->primaryTenant();
        $secondaryTenant = $this->secondaryTenant();

        $primaryOutboxId = $this->createPendingOutboxEvent($primaryTenant, 'primary');
        $secondaryOutboxId = $this->createPendingOutboxEvent($secondaryTenant, 'secondary');

        $processedTenantIds = $this->captureProcessedTenantIdsForJob(
            ProcessTicketOutboxJob::class,
            fn () => $this->runScheduledCallbackByName('ticketing:outbox:process')
        );

        $this->assertContains((string) $primaryTenant->getAttribute('_id'), $processedTenantIds);
        $this->assertContains((string) $secondaryTenant->getAttribute('_id'), $processedTenantIds);

        $this->assertOutboxProcessed($primaryTenant, $primaryOutboxId);
        $this->assertOutboxProcessed($secondaryTenant, $secondaryOutboxId);
    }

    public function test_ticket_outbox_job_emits_payload_safe_run_summary_telemetry_with_tenant_context(): void
    {
        $tenant = $this->primaryTenant();
        $tenantId = (string) $tenant->getAttribute('_id');

        $this->createPendingOutboxEvent($tenant, 'telemetry', [
            'source' => 'scheduler-runtime-test',
            'secret' => 'must-not-leak',
            'email' => 'hidden@example.com',
            'token' => 'must-not-leak',
        ]);

        $tenant->makeCurrent();

        try {
            Log::spy();

            (new ProcessTicketOutboxJob)->handle();
        } finally {
            $tenant->forgetCurrent();
        }

        Log::shouldHaveReceived('info')->with(
            'ticketing_outbox_run_summary',
            \Mockery::on(static function (array $context) use ($tenantId): bool {
                $keys = array_keys($context);
                sort($keys);

                return $keys === ['batch_size', 'failed_count', 'processed_count', 'tenant_id']
                    && ($context['tenant_id'] ?? null) === $tenantId
                    && ($context['processed_count'] ?? null) === 1
                    && ($context['failed_count'] ?? null) === 0
                    && ($context['batch_size'] ?? null) === 100;
            })
        )->once();
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

    private function createPendingOutboxEvent(Tenant $tenant, string $suffix, array $payload = ['source' => 'scheduler-runtime-test']): string
    {
        $tenant->makeCurrent();

        try {
            $outbox = TicketOutboxEvent::query()->create([
                'topic' => sprintf('scheduler-outbox-%s', $suffix),
                'status' => 'pending',
                'payload' => $payload,
                'dedupe_key' => sprintf('scheduler-outbox-%s-%s', $suffix, Carbon::now()->format('Uu')),
                'available_at' => Carbon::now()->subSecond(),
                'attempts' => 0,
            ]);

            return (string) $outbox->getAttribute('_id');
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

    private function assertOutboxProcessed(Tenant $tenant, string $outboxEventId): void
    {
        $tenant->makeCurrent();

        try {
            $outbox = TicketOutboxEvent::query()->findOrFail($outboxEventId);

            $this->assertSame('processed', (string) ($outbox->status ?? ''));
            $this->assertNotNull($outbox->processed_at);
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
