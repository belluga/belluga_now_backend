<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

class EventOccurrenceSyncService
{
    private const DEFAULT_EVENT_DURATION_HOURS = 3;

    /**
     * @param  array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>  $occurrences
     */
    public function syncFromEvent(Event $event, array $occurrences): void
    {
        $eventId = (string) $event->_id;
        $now = Carbon::now();
        $publication = $this->normalizePublication($event->publication ?? [], $event->created_at);
        $geoLocation = $this->resolveEventGeoLocation($event);

        $activeIndexes = [];
        foreach ($occurrences as $index => $occurrence) {
            $start = $this->toCarbon($occurrence['date_time_start'] ?? null) ?? $now;
            $end = $this->toCarbon($occurrence['date_time_end'] ?? null);
            $effectiveEnd = $this->resolveEffectiveEnd($start, $end);
            $eventTaxonomyTerms = $this->normalizeArray($event->taxonomy_terms ?? []);

            $payload = [
                'event_id' => $eventId,
                'occurrence_index' => $index,
                'slug' => (string) ($event->slug ?? ''),
                'occurrence_slug' => $this->buildOccurrenceSlug((string) ($event->slug ?? ''), $eventId, $index),
                'title' => (string) ($event->title ?? ''),
                'content' => (string) ($event->content ?? ''),
                'type' => $this->normalizeArray($event->type ?? []),
                'thumb' => $this->normalizeArray($event->thumb ?? null),
                'location' => $this->normalizeArray($event->location ?? []),
                'place_ref' => $this->normalizeArray($event->place_ref ?? null),
                'venue' => $this->normalizeArray($event->venue ?? null),
                'geo_location' => $geoLocation,
                'artists' => $this->deriveArtistsReadProjection($this->normalizeArray($event->event_parties ?? [])),
                'tags' => $this->normalizeArray($event->tags ?? []),
                'categories' => $this->normalizeArray($event->categories ?? []),
                'taxonomy_terms' => $eventTaxonomyTerms,
                'capabilities' => $this->normalizeArray($event->capabilities ?? []),
                'created_by' => $this->normalizeArray($event->created_by ?? []),
                'event_parties' => $this->normalizeArray($event->event_parties ?? []),
                'publication' => $publication,
                'is_event_published' => $this->isEffectivelyPublished($publication, $now),
                'is_active' => (bool) ($event->is_active ?? true),
                'starts_at' => $start,
                'ends_at' => $end,
                'effective_ends_at' => $effectiveEnd,
                'updated_from_event_at' => $now,
                'deleted_at' => null,
            ];

            $document = EventOccurrence::withTrashed()
                ->where('event_id', $eventId)
                ->where('occurrence_index', $index)
                ->first();

            if ($document) {
                $document->fill($payload);
                $document->save();
            } else {
                EventOccurrence::query()->create($payload);
            }

            $activeIndexes[] = $index;
        }

        $query = EventOccurrence::query()->where('event_id', $eventId);
        if ($activeIndexes !== []) {
            $query->whereNotIn('occurrence_index', $activeIndexes);
        }

        $query->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $publication
     */
    public function mirrorPublicationByEventId(string $eventId, array $publication): int
    {
        $now = Carbon::now();

        return EventOccurrence::query()->where('event_id', $eventId)->update([
            'publication' => $publication,
            'is_event_published' => $this->isEffectivelyPublished($publication, $now),
            'updated_from_event_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function softDeleteByEventId(string $eventId): void
    {
        $now = Carbon::now();

        EventOccurrence::query()->where('event_id', $eventId)->update([
            'deleted_at' => $now,
            'updated_at' => $now,
            'updated_from_event_at' => $now,
        ]);
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

    private function resolveEventGeoLocation(Event $event): array
    {
        $location = $this->normalizeArray($event->location ?? []);
        $geo = $this->normalizeArray($location['geo'] ?? null);

        if ($geo !== []) {
            return $geo;
        }

        return $this->normalizeArray($event->geo_location ?? null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function deriveArtistsReadProjection(array $eventParties): array
    {
        $profiles = [];

        foreach ($eventParties as $party) {
            $partyPayload = $this->normalizeArray($party);
            $partyType = trim((string) ($partyPayload['party_type'] ?? ''));
            if ($partyType === 'venue') {
                continue;
            }

            $metadata = $this->normalizeArray($partyPayload['metadata'] ?? []);
            $profileId = trim((string) ($partyPayload['party_ref_id'] ?? ''));
            $displayName = trim((string) ($metadata['display_name'] ?? ''));
            if ($profileId === '' || $displayName === '') {
                continue;
            }

            $profiles[] = [
                'id' => $profileId,
                'display_name' => $displayName,
                'slug' => isset($metadata['slug']) ? (string) $metadata['slug'] : null,
                'profile_type' => isset($metadata['profile_type']) ? (string) $metadata['profile_type'] : $partyType,
                'avatar_url' => $metadata['avatar_url'] ?? null,
                'cover_url' => $metadata['cover_url'] ?? null,
                'highlight' => false,
                'genres' => array_values($this->normalizeArray($metadata['genres'] ?? [])),
                'taxonomy_terms' => $this->normalizeArray($metadata['taxonomy_terms'] ?? []),
            ];
        }

        return $profiles;
    }

    private function resolveEffectiveEnd(Carbon $start, ?Carbon $end): Carbon
    {
        if ($end !== null) {
            return $end;
        }

        return $start->copy()->addHours(self::DEFAULT_EVENT_DURATION_HOURS);
    }

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
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePublication(mixed $publication, mixed $fallbackDate): array
    {
        $payload = $this->normalizeArray($publication);
        $status = (string) ($payload['status'] ?? 'draft');
        $publishAt = $this->toCarbon($payload['publish_at'] ?? null) ?? $this->toCarbon($fallbackDate) ?? Carbon::now();

        return [
            'status' => $status,
            'publish_at' => $publishAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $publication
     */
    private function isEffectivelyPublished(array $publication, Carbon $now): bool
    {
        $status = (string) ($publication['status'] ?? 'draft');
        if ($status !== 'published') {
            return false;
        }

        $publishAt = $this->toCarbon($publication['publish_at'] ?? null);

        return $publishAt === null || $publishAt->lessThanOrEqualTo($now);
    }

    private function buildOccurrenceSlug(string $eventSlug, string $eventId, int $index): string
    {
        $base = trim($eventSlug) !== '' ? trim($eventSlug) : ('event-'.substr($eventId, 0, 8));

        return sprintf('%s-occ-%d', $base, $index + 1);
    }
}
