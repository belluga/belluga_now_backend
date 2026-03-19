<?php

declare(strict_types=1);

namespace App\Jobs\Telemetry;

use App\Application\Telemetry\TelemetryDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class DeliverTelemetryEventJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<int, array<string, mixed>>  $trackers
     */
    public function __construct(
        private readonly array $envelope,
        private readonly array $trackers
    ) {
        $tries = (int) config('telemetry.queue.tries', 5);
        if ($tries > 0) {
            $this->tries = $tries;
        }

        $queueName = config('telemetry.queue.name', 'default');
        if (is_string($queueName) && $queueName !== '') {
            $this->onQueue($queueName);
        }
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('telemetry.queue.backoff_seconds', [5, 15, 30, 60]);
        if (! is_array($backoff) || $backoff === []) {
            return [5, 15, 30, 60];
        }

        $normalized = [];
        foreach ($backoff as $seconds) {
            if (is_int($seconds) && $seconds > 0) {
                $normalized[] = $seconds;
            }
        }

        return $normalized !== [] ? $normalized : [5, 15, 30, 60];
    }

    public function handle(TelemetryDeliveryService $deliveryService): void
    {
        $deliveryService->deliver($this->envelope, $this->trackers);
    }
}
