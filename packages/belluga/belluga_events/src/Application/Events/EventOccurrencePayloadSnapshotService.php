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
            'taxonomy_terms' => [],
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
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->get()
            ->values()
            ->all();

        $orderedDocuments = $this->orderOccurrenceDocuments($fromCollection, $event);

        $occurrences = [];
        foreach ($orderedDocuments as $order => $occurrence) {
            $start = $this->toCarbon($occurrence->starts_at ?? null);
            if (! $start) {
                continue;
            }

            $end = $this->toCarbon($occurrence->ends_at ?? null);
            if ($end && $end->lessThan($start)) {
                continue;
            }

            $occurrences[] = [
                'occurrence_id' => (string) $occurrence->_id,
                'occurrence_slug' => (string) ($occurrence->occurrence_slug ?? ''),
                'date_time_start' => $start,
                'date_time_end' => $end,
                'event_parties' => $this->normalizeArray($occurrence->own_event_parties ?? []),
                'has_location_override' => false,
                'location_override' => null,
                'taxonomy_terms' => $this->normalizeArray($occurrence->own_taxonomy_terms ?? []),
                'programming_items' => $this->normalizeArray($occurrence->programming_items ?? []),
            ];
        }

        return $occurrences;
    }

    /**
     * @param  array<int, EventOccurrence>  $documents
     * @return array<int, EventOccurrence>
     */
    private function orderOccurrenceDocuments(array $documents, Event $event): array
    {
        $occurrenceRefs = $this->normalizeOccurrenceRefs($event->occurrence_refs ?? []);
        if ($occurrenceRefs === []) {
            return $documents;
        }

        $orderById = [];
        $orderBySlug = [];
        foreach ($occurrenceRefs as $ref) {
            $order = (int) ($ref['order'] ?? count($orderById));
            $occurrenceId = $this->normalizeOptionalString($ref['occurrence_id'] ?? null);
            $occurrenceSlug = $this->normalizeOptionalString($ref['occurrence_slug'] ?? null);
            if ($occurrenceId !== null) {
                $orderById[$occurrenceId] = $order;
            }
            if ($occurrenceSlug !== null) {
                $orderBySlug[$occurrenceSlug] = $order;
            }
        }

        usort($documents, function (EventOccurrence $left, EventOccurrence $right) use ($orderById, $orderBySlug): int {
            $leftRank = $this->resolveDocumentOrderRank($left, $orderById, $orderBySlug);
            $rightRank = $this->resolveDocumentOrderRank($right, $orderById, $orderBySlug);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            $leftStart = $this->toCarbon($left->starts_at)?->getTimestamp() ?? PHP_INT_MAX;
            $rightStart = $this->toCarbon($right->starts_at)?->getTimestamp() ?? PHP_INT_MAX;
            if ($leftStart !== $rightStart) {
                return $leftStart <=> $rightStart;
            }

            return strcmp((string) ($left->_id ?? ''), (string) ($right->_id ?? ''));
        });

        return $documents;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOccurrenceRefs(mixed $value): array
    {
        $refs = $this->normalizeArray($value);

        return array_values(array_filter($refs, static fn (mixed $ref): bool => is_array($ref)));
    }

    /**
     * @param  array<string, int>  $orderById
     * @param  array<string, int>  $orderBySlug
     */
    private function resolveDocumentOrderRank(EventOccurrence $document, array $orderById, array $orderBySlug): int
    {
        $documentId = isset($document->_id) ? (string) $document->_id : null;
        if ($documentId !== null && array_key_exists($documentId, $orderById)) {
            return $orderById[$documentId];
        }

        $slug = $this->normalizeOptionalString($document->occurrence_slug ?? null);
        if ($slug !== null && array_key_exists($slug, $orderBySlug)) {
            return $orderBySlug[$slug];
        }

        return PHP_INT_MAX;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
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
