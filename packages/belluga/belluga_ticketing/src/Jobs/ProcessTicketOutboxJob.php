<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Jobs;

use Belluga\Ticketing\Models\Tenants\TicketOutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\TenantAware;

class ProcessTicketOutboxJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $batchSize = 100) {}

    public function handle(): void
    {
        $processedCount = 0;
        $failedCount = 0;

        /** @var array<int, TicketOutboxEvent> $events */
        $events = TicketOutboxEvent::query()
            ->where('status', 'pending')
            ->where('available_at', '<=', Carbon::now())
            ->orderBy('created_at')
            ->limit($this->batchSize)
            ->get()
            ->all();

        foreach ($events as $event) {
            try {
                // Integration dispatch remains adapter-driven. For MVP we only persist deterministic processing audit.
                Log::info('ticketing_outbox_event_processed', [
                    'outbox_event_id' => (string) $event->getAttribute('_id'),
                    'topic' => (string) $event->topic,
                ]);

                $event->status = 'processed';
                $event->processed_at = Carbon::now();
                $event->attempts = (int) ($event->attempts ?? 0) + 1;
                $event->last_error = null;
                $event->save();
                $processedCount++;
            } catch (\Throwable $throwable) {
                $event->status = 'pending';
                $event->attempts = (int) ($event->attempts ?? 0) + 1;
                $event->last_error = $throwable->getMessage();
                $event->available_at = Carbon::now()->addSeconds(30);
                $event->save();
                $failedCount++;

                Log::warning('ticketing_outbox_event_failed', [
                    'outbox_event_id' => (string) $event->getAttribute('_id'),
                    'topic' => (string) $event->topic,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }

        Log::info('ticketing_outbox_run_summary', [
            'tenant_id' => $this->currentTenantId(),
            'processed_count' => $processedCount,
            'failed_count' => $failedCount,
            'batch_size' => $this->batchSize,
        ]);
    }

    private function currentTenantId(): ?string
    {
        $tenantId = Context::get('tenantId');
        if (is_string($tenantId) && $tenantId !== '') {
            return $tenantId;
        }

        $currentTenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        if (! is_object($currentTenant) || ! method_exists($currentTenant, 'getAttribute')) {
            return null;
        }

        $candidate = $currentTenant->getAttribute('_id') ?? $currentTenant->getAttribute('id');

        return is_scalar($candidate) && $candidate !== ''
            ? (string) $candidate
            : null;
    }
}
