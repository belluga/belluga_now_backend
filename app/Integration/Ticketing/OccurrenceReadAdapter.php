<?php

declare(strict_types=1);

namespace App\Integration\Ticketing;

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use MongoDB\BSON\ObjectId;

class OccurrenceReadAdapter implements OccurrenceReadContract
{
    public function findOccurrence(string $eventId, string $occurrenceId): ?array
    {
        $occurrence = EventOccurrence::query()
            ->where('_id', $occurrenceId)
            ->where('event_id', $eventId)
            ->first();

        if (! $occurrence) {
            return null;
        }

        $event = Event::query()->find($eventId);
        $ticketing = is_array($event?->getAttribute('ticketing') ?? null)
            ? $event->getAttribute('ticketing')
            : [];
        $eventHoldMinutes = is_array($ticketing)
            ? ($ticketing['hold_minutes'] ?? null)
            : null;

        return [
            'id' => (string) $occurrence->getAttribute('_id'),
            'event_id' => (string) $occurrence->getAttribute('event_id'),
            'occurrence_slug' => (string) ($occurrence->getAttribute('occurrence_slug') ?? ''),
            'starts_at' => $occurrence->getAttribute('starts_at'),
            'ends_at' => $occurrence->getAttribute('ends_at'),
            'is_event_published' => (bool) $occurrence->getAttribute('is_event_published'),
            'deleted_at' => $occurrence->getAttribute('deleted_at'),
            'event_hold_minutes' => is_numeric($eventHoldMinutes) ? (int) $eventHoldMinutes : null,
        ];
    }

    public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array
    {
        $occurrenceRef = trim($occurrenceRef);
        if ($occurrenceRef === '') {
            return null;
        }

        if ($eventRef !== null && trim($eventRef) !== '') {
            $eventId = $this->resolveEventId(trim($eventRef));
            if (! $eventId) {
                return null;
            }

            /** @var EventOccurrence|null $occurrence */
            $occurrence = EventOccurrence::query()
                ->where('event_id', $eventId)
                ->where(function ($query) use ($occurrenceRef): void {
                    if ($this->looksLikeObjectId($occurrenceRef)) {
                        $query->orWhere('_id', $occurrenceRef);
                    }

                    if (is_numeric($occurrenceRef)) {
                        $query->orWhere('occurrence_index', (int) $occurrenceRef);
                    }

                    $query->orWhere('occurrence_slug', $occurrenceRef);
                })
                ->first();

            if (! $occurrence) {
                return null;
            }

            return [
                'event_id' => $eventId,
                'occurrence_id' => (string) $occurrence->getAttribute('_id'),
            ];
        }

        /** @var EventOccurrence|null $occurrence */
        $occurrence = EventOccurrence::query()
            ->where(function ($query) use ($occurrenceRef): void {
                if ($this->looksLikeObjectId($occurrenceRef)) {
                    $query->orWhere('_id', $occurrenceRef);
                }
                $query->orWhere('occurrence_slug', $occurrenceRef);
            })
            ->first();

        if (! $occurrence) {
            return null;
        }

        return [
            'event_id' => (string) $occurrence->getAttribute('event_id'),
            'occurrence_id' => (string) $occurrence->getAttribute('_id'),
        ];
    }

    private function resolveEventId(string $eventRef): ?string
    {
        if ($this->looksLikeObjectId($eventRef)) {
            /** @var Event|null $event */
            $event = Event::query()->where('_id', new ObjectId($eventRef))->first();
            if ($event) {
                return (string) $event->getAttribute('_id');
            }

            $event = Event::query()->where('_id', $eventRef)->first();
            if ($event) {
                return (string) $event->getAttribute('_id');
            }
        }

        /** @var Event|null $eventBySlug */
        $eventBySlug = Event::query()->where('slug', $eventRef)->first();
        if (! $eventBySlug) {
            return null;
        }

        return (string) $eventBySlug->getAttribute('_id');
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
