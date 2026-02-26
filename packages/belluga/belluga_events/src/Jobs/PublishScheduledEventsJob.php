<?php

declare(strict_types=1);

namespace Belluga\Events\Jobs;

use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PublishScheduledEventsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 20, 40];
    }

    public function handle(
        TenantExecutionContextContract $tenantExecutionContext,
        EventOccurrenceSyncService $occurrenceSyncService,
        Dispatcher $events
    ): void {
        $now = Carbon::now();

        $tenantExecutionContext->runForEachTenant(function () use ($now, $occurrenceSyncService, $events): void {
            $scheduledEvents = Event::query()
                ->where('publication.status', 'publish_scheduled')
                ->where('publication.publish_at', '<=', $now)
                ->get();

            if ($scheduledEvents->isEmpty()) {
                return;
            }

            foreach ($scheduledEvents as $scheduledEvent) {
                $eventId = (string) $scheduledEvent->_id;

                $published = $this->runTenantTransaction(function () use ($eventId, $now, $occurrenceSyncService): bool {
                    $event = Event::query()->where('_id', $eventId)->first();
                    if (! $event) {
                        return false;
                    }

                    $publication = is_array($event->publication ?? null)
                        ? $event->publication
                        : (array) ($event->publication ?? []);

                    if (($publication['status'] ?? null) !== 'publish_scheduled') {
                        return false;
                    }

                    $publishAt = $publication['publish_at'] ?? null;
                    $publishAtCarbon = $publishAt instanceof Carbon
                        ? $publishAt
                        : ($publishAt instanceof \DateTimeInterface ? Carbon::instance($publishAt) : (is_string($publishAt) ? Carbon::parse($publishAt) : null));

                    if ($publishAtCarbon !== null && $publishAtCarbon->greaterThan($now)) {
                        return false;
                    }

                    $publication['status'] = 'published';
                    if (! isset($publication['publish_at'])) {
                        $publication['publish_at'] = $now;
                    }

                    $event->publication = $publication;
                    $event->save();

                    $occurrenceSyncService->mirrorPublicationByEventId($eventId, $publication);

                    return true;
                });

                if ($published) {
                    $events->dispatch(new EventUpdated($eventId));
                }
            }
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function runTenantTransaction(callable $callback): mixed
    {
        $connection = DB::connection('tenant');

        if (! method_exists($connection, 'transaction')) {
            throw new RuntimeException(
                'Tenant MongoDB transaction support is required for events writes, but the active driver has no transaction API.'
            );
        }

        try {
            return $connection->transaction(static fn () => $callback());
        } catch (\Throwable $throwable) {
            if ($this->isTransactionSupportError($throwable)) {
                throw new RuntimeException(
                    'Tenant MongoDB transaction support is required for events writes. Configure replica set / transaction-capable runtime.',
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }
    }

    private function isTransactionSupportError(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'transaction numbers are only allowed')
            || str_contains($message, 'transactions are not supported')
            || str_contains($message, 'replica set')
            || str_contains($message, 'mongos')
            || str_contains($message, 'starttransaction');
    }
}
