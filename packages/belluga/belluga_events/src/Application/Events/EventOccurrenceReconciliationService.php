<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

class EventOccurrenceReconciliationService
{
    public function __construct(
        private readonly TenantExecutionContextContract $tenantExecutionContext,
        private readonly EventOccurrenceSyncService $occurrenceSyncService
    ) {
    }

    public function reconcileAllTenants(): void
    {
        $this->tenantExecutionContext->runForEachTenant(function (): void {
            $this->reconcileCurrentTenant();
        });
    }

    private function reconcileCurrentTenant(): void
    {
        Event::withTrashed()
            ->get()
            ->each(function (Event $event): void {
                $eventId = (string) $event->_id;

                if ($event->trashed()) {
                    $this->runTenantTransaction(function () use ($eventId): void {
                        $this->occurrenceSyncService->softDeleteByEventId($eventId);
                    });

                    return;
                }

                $occurrences = $this->resolveOccurrences($event);
                if ($occurrences === []) {
                    Log::warning('events_occurrence_reconciliation_skipped_missing_schedule', [
                        'event_id' => $eventId,
                    ]);

                    return;
                }

                $this->runTenantTransaction(function () use ($event, $occurrences): void {
                    $this->occurrenceSyncService->syncFromEvent($event, $occurrences);
                });
            });
    }

    /**
     * @return array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>
     */
    private function resolveOccurrences(Event $event): array
    {
        $eventId = (string) $event->_id;

        $occurrences = EventOccurrence::withTrashed()
            ->where('event_id', $eventId)
            ->orderBy('occurrence_index')
            ->get()
            ->map(function (EventOccurrence $occurrence): ?array {
                $start = $this->toCarbon($occurrence->starts_at ?? null);
                if (! $start) {
                    return null;
                }

                $end = $this->toCarbon($occurrence->ends_at ?? null);
                if ($end && $end->lessThan($start)) {
                    $end = null;
                }

                return [
                    'date_time_start' => $start,
                    'date_time_end' => $end,
                ];
            })
            ->filter(static fn (?array $row): bool => $row !== null)
            ->values()
            ->all();

        if ($occurrences !== []) {
            return $occurrences;
        }

        $fallbackStart = $this->toCarbon($event->date_time_start ?? null);
        if (! $fallbackStart) {
            return [];
        }

        $fallbackEnd = $this->toCarbon($event->date_time_end ?? null);
        if ($fallbackEnd && $fallbackEnd->lessThan($fallbackStart)) {
            $fallbackEnd = null;
        }

        return [[
            'date_time_start' => $fallbackStart,
            'date_time_end' => $fallbackEnd,
        ]];
    }

    /**
     * @param mixed $value
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
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

