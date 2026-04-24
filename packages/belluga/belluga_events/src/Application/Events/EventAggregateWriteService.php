<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EventAggregateWriteService
{
    public function __construct(
        private readonly EventTransactionRunner $transactions,
        private readonly EventOccurrenceSyncService $occurrenceSyncService,
        private readonly EventOccurrencePayloadSnapshotService $occurrencePayloadSnapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function create(array $payload, array $occurrences): Event
    {
        /** @var Event $event */
        $event = $this->transactions->run(function () use ($payload, $occurrences): Event {
            $created = Event::query()->create($payload);
            $this->occurrenceSyncService->syncFromEvent($created, $occurrences);

            return $created->fresh() ?? $created;
        });

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function update(Event $event, array $payload, array $occurrences): Event
    {
        /** @var Event $updated */
        $updated = $this->transactions->run(function () use ($event, $payload, $occurrences): Event {
            $event->fill($payload);
            $event->save();

            $fresh = $event->fresh() ?? $event;
            $this->occurrenceSyncService->syncFromEvent($fresh, $occurrences);

            return $fresh;
        });

        return $updated;
    }

    public function delete(Event $event): void
    {
        $eventId = (string) $event->_id;

        $this->transactions->run(function () use ($event, $eventId): null {
            $event->delete();
            $this->occurrenceSyncService->softDeleteByEventId($eventId);

            return null;
        });
    }

    public function repairOccurrences(Event $event): void
    {
        $eventId = (string) $event->_id;
        $occurrences = $this->occurrencePayloadSnapshots->resolveForRepair($event);

        if ($event->trashed()) {
            $deletedAt = $event->deleted_at;

            $this->transactions->run(function () use ($event, $eventId, $occurrences, $deletedAt): null {
                if ($occurrences !== []) {
                    $this->occurrenceSyncService->syncFromEvent($event, $occurrences);
                }

                $this->occurrenceSyncService->softDeleteByEventId($eventId, $deletedAt);

                return null;
            });

            return;
        }

        if ($occurrences === []) {
            Log::warning('events_occurrence_reconciliation_skipped_missing_schedule', [
                'event_id' => $eventId,
            ]);

            return;
        }

        $this->transactions->run(function () use ($event, $occurrences): null {
            $this->occurrenceSyncService->syncFromEvent($event, $occurrences);

            return null;
        });
    }

    /**
     * @return array{published: bool, from_status?: string, to_status?: string, publish_at?: mixed, mirrored_occurrences?: int}
     */
    public function publishScheduledEventIfDue(string $eventId, Carbon $now): array
    {
        /** @var array{published: bool, from_status?: string, to_status?: string, publish_at?: mixed, mirrored_occurrences?: int} $result */
        $result = $this->transactions->run(function () use ($eventId, $now): array {
            $event = Event::query()->where('_id', $eventId)->first();
            if (! $event) {
                return ['published' => false];
            }

            $publication = is_array($event->publication ?? null)
                ? $event->publication
                : (array) ($event->publication ?? []);
            $fromStatus = (string) ($publication['status'] ?? 'draft');

            if ($fromStatus !== 'publish_scheduled') {
                return ['published' => false];
            }

            $publishAt = $this->toCarbon($publication['publish_at'] ?? null);
            if ($publishAt !== null && $publishAt->greaterThan($now)) {
                return ['published' => false];
            }

            $publication['status'] = 'published';
            if (! isset($publication['publish_at'])) {
                $publication['publish_at'] = $now;
            }

            $event->publication = $publication;
            $event->save();

            $mirrored = $this->occurrenceSyncService->mirrorPublicationByEventId($eventId, $publication, $now);

            return [
                'published' => true,
                'from_status' => $fromStatus,
                'to_status' => 'published',
                'publish_at' => $publication['publish_at'] ?? null,
                'mirrored_occurrences' => (int) $mirrored,
            ];
        });

        return $result;
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
