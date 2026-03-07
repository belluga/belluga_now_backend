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
use Illuminate\Support\Facades\Log;

class ProcessTicketOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $batchSize = 100)
    {
    }

    public function handle(): void
    {
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
            } catch (\Throwable $throwable) {
                $event->status = 'pending';
                $event->attempts = (int) ($event->attempts ?? 0) + 1;
                $event->last_error = $throwable->getMessage();
                $event->available_at = Carbon::now()->addSeconds(30);
                $event->save();

                Log::warning('ticketing_outbox_event_failed', [
                    'outbox_event_id' => (string) $event->getAttribute('_id'),
                    'topic' => (string) $event->topic,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }
}
