<?php

declare(strict_types=1);

namespace App\Integration\Invites;

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use MongoDB\BSON\ObjectId;

class InviteTargetReadAdapter implements InviteTargetReadContract
{
    public function findEventByIdOrSlug(string $eventRef): ?array
    {
        $event = null;
        if ($this->looksLikeObjectId($eventRef)) {
            /** @var Event|null $event */
            $event = Event::query()->where('_id', new ObjectId($eventRef))->first();
        }

        if (! $event) {
            /** @var Event|null $event */
            $event = Event::query()->where('slug', $eventRef)->first();
        }

        if (! $event) {
            return null;
        }

        return [
            'id' => (string) $event->_id,
            'slug' => (string) ($event->slug ?? ''),
            'title' => (string) ($event->title ?? ''),
            'date_time_start' => $event->date_time_start,
            'date_time_end' => $event->date_time_end,
            'publication' => $event->publication,
            'attributes' => $this->normalizeArray($event->getAttributes()),
        ];
    }

    public function findOccurrenceForEvent(string $eventId, string $occurrenceRef): ?array
    {
        $query = EventOccurrence::query()->where('event_id', $eventId);
        $occurrence = null;

        if ($this->looksLikeObjectId($occurrenceRef)) {
            /** @var EventOccurrence|null $occurrence */
            $occurrence = (clone $query)->where('_id', new ObjectId($occurrenceRef))->first();
        }

        if (! $occurrence) {
            /** @var EventOccurrence|null $occurrence */
            $occurrence = (clone $query)->where('occurrence_slug', $occurrenceRef)->first();
        }

        if (! $occurrence) {
            return null;
        }

        return [
            'id' => (string) $occurrence->_id,
            'starts_at' => $occurrence->starts_at,
            'ends_at' => $occurrence->ends_at,
            'is_event_published' => (bool) ($occurrence->is_event_published ?? false),
            'attributes' => $this->normalizeArray($occurrence->getAttributes()),
        ];
    }

    public function countOccurrencesForEvent(string $eventId, int $limit = 2): int
    {
        return EventOccurrence::query()
            ->where('event_id', $eventId)
            ->limit(max(1, $limit))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
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

    private function looksLikeObjectId(string $value): bool
    {
        return preg_match('/^[a-f0-9]{24}$/i', $value) === 1;
    }
}
