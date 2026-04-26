<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

class EventOccurrencePayloadSnapshotService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function requireForUpdate(Event $event): array
    {
        $occurrences = $this->loadStoredOccurrencePayloads($event, includeTrashed: false);

        if ($occurrences === []) {
            throw new RuntimeException(
                'Event occurrences are required for updates without schedule mutation. '.
                'Provide occurrences payload to rebuild the schedule.'
            );
        }

        return $occurrences;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveForRepair(Event $event): array
    {
        $occurrences = $this->loadStoredOccurrencePayloads($event, includeTrashed: $event->trashed());
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
            'event_parties' => [],
            'has_location_override' => false,
            'location_override' => null,
            'programming_items' => [],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStoredOccurrencePayloads(Event $event, bool $includeTrashed): array
    {
        $eventId = (string) $event->_id;
        $query = $includeTrashed
            ? EventOccurrence::withTrashed()
            : EventOccurrence::query();

        $fromCollection = $query
            ->where('event_id', $eventId)
            ->orderBy('occurrence_index')
            ->get();

        $occurrences = [];
        foreach ($fromCollection as $occurrence) {
            $start = $this->toCarbon($occurrence->starts_at ?? null);
            if (! $start) {
                continue;
            }

            $end = $this->toCarbon($occurrence->ends_at ?? null);
            if ($end && $end->lessThan($start)) {
                continue;
            }

            $occurrences[] = [
                'date_time_start' => $start,
                'date_time_end' => $end,
                'event_parties' => $this->normalizeArray($occurrence->own_event_parties ?? []),
                'has_location_override' => false,
                'location_override' => null,
                'programming_items' => $this->normalizeArray($occurrence->programming_items ?? []),
            ];
        }

        return $occurrences;
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
