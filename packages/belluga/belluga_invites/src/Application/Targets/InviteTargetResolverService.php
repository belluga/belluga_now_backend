<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Targets;

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class InviteTargetResolverService
{
    public function __construct(
        private readonly InviteRuntimeSettingsService $runtimeSettings,
    ) {}

    /**
     * @param  array{event_id:string,occurrence_id?:string|null}  $targetRef
     * @return array{
     *     target_ref: array{event_id:string,occurrence_id:?string},
     *     event_snapshot: array{
     *         event_name:string,
     *         event_slug:string,
     *         event_date:?Carbon,
     *         event_image_url:?string,
     *         location:string,
     *         host_name:string,
     *         tags:array<int,string>,
     *         attendance_policy:string,
     *         expires_at:?Carbon
     *     }
     * }
     */
    public function resolve(array $targetRef): array
    {
        $eventRef = trim((string) ($targetRef['event_id'] ?? ''));
        $occurrenceRef = isset($targetRef['occurrence_id']) ? trim((string) $targetRef['occurrence_id']) : '';

        if ($eventRef === '') {
            throw new InviteDomainException('target_event_required', 422);
        }

        $event = $this->findEventByIdOrSlug($eventRef);
        if (! $event) {
            throw new InviteDomainException('target_not_found', 404);
        }

        $occurrence = $occurrenceRef !== ''
            ? $this->findOccurrenceForEvent($event, $occurrenceRef)
            : null;

        if ($occurrenceRef !== '' && ! $occurrence) {
            throw new InviteDomainException('target_not_found', 404);
        }

        if ($occurrence === null && $this->eventHasMultipleOccurrences((string) $event->_id)) {
            throw new InviteDomainException(
                errorCode: 'target_occurrence_required',
                httpStatus: 422,
                message: 'occurrence_id is required for multi-occurrence events.'
            );
        }

        $this->assertPublished($event, $occurrence);

        $eventDate = $occurrence?->starts_at instanceof Carbon
            ? $occurrence->starts_at
            : ($event->date_time_start instanceof Carbon ? $event->date_time_start : null);
        $expiresAt = $occurrence?->ends_at instanceof Carbon
            ? $occurrence->ends_at
            : ($event->date_time_end instanceof Carbon ? $event->date_time_end : null);

        $eventPayload = $this->normalizeArray($event->getAttributes());
        $occurrencePayload = $occurrence ? $this->normalizeArray($occurrence->getAttributes()) : [];
        $location = $this->resolveLocationLabel($eventPayload);
        $hostName = $this->resolveHostName($eventPayload);
        $thumb = $this->normalizeArray($eventPayload['thumb'] ?? []);
        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);

        return [
            'target_ref' => [
                'event_id' => (string) $event->_id,
                'occurrence_id' => $occurrence ? (string) $occurrence->_id : null,
            ],
            'event_snapshot' => [
                'event_name' => (string) ($event->title ?? ''),
                'event_slug' => (string) ($event->slug ?? ''),
                'event_date' => $eventDate,
                'event_image_url' => $this->resolveImageUrl($thumb, $venue),
                'location' => $location,
                'host_name' => $hostName,
                'tags' => array_values(array_map('strval', $this->normalizeArray($eventPayload['tags'] ?? []))),
                'attendance_policy' => $this->runtimeSettings->resolveAttendancePolicy(
                    $eventPayload['attendance_policy'] ?? null,
                    $occurrencePayload['attendance_policy'] ?? null,
                ),
                'expires_at' => $expiresAt,
            ],
        ];
    }

    private function findEventByIdOrSlug(string $eventRef): ?Event
    {
        if ($this->looksLikeObjectId($eventRef)) {
            $event = Event::query()->where('_id', new ObjectId($eventRef))->first();
            if ($event) {
                return $event;
            }
        }

        return Event::query()->where('slug', $eventRef)->first();
    }

    private function findOccurrenceForEvent(Event $event, string $occurrenceRef): ?EventOccurrence
    {
        $query = EventOccurrence::query()->where('event_id', (string) $event->_id);

        if ($this->looksLikeObjectId($occurrenceRef)) {
            $occurrence = (clone $query)->where('_id', new ObjectId($occurrenceRef))->first();
            if ($occurrence) {
                return $occurrence;
            }
        }

        return $query->where('occurrence_slug', $occurrenceRef)->first();
    }

    private function eventHasMultipleOccurrences(string $eventId): bool
    {
        return EventOccurrence::query()
            ->where('event_id', $eventId)
            ->limit(2)
            ->count() > 1;
    }

    private function assertPublished(Event $event, ?EventOccurrence $occurrence): void
    {
        $publication = $this->normalizeArray($event->publication ?? []);
        $status = (string) ($publication['status'] ?? 'draft');
        $publishAt = $publication['publish_at'] ?? null;

        if ($publishAt instanceof \MongoDB\BSON\UTCDateTime) {
            $publishAt = Carbon::instance($publishAt->toDateTime());
        }
        if ($publishAt instanceof \DateTimeInterface && ! $publishAt instanceof Carbon) {
            $publishAt = Carbon::instance($publishAt);
        }

        if ($status !== 'published' || ($publishAt instanceof Carbon && $publishAt->isFuture())) {
            throw new InviteDomainException('target_not_available', 404);
        }

        if ($occurrence !== null && ! (bool) ($occurrence->is_event_published ?? false)) {
            throw new InviteDomainException('target_not_available', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
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

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function resolveLocationLabel(array $eventPayload): string
    {
        $location = $this->normalizeArray($eventPayload['location'] ?? []);
        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);

        foreach ([$location['label'] ?? null, $location['name'] ?? null, $venue['display_name'] ?? null, $venue['name'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Belluga Event';
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     */
    private function resolveHostName(array $eventPayload): string
    {
        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);
        if (isset($venue['display_name']) && is_string($venue['display_name']) && trim($venue['display_name']) !== '') {
            return trim($venue['display_name']);
        }

        $eventParties = $this->normalizeArray($eventPayload['event_parties'] ?? []);
        foreach ($eventParties as $party) {
            $payload = $this->normalizeArray($party);
            $displayName = $payload['display_name'] ?? $payload['name'] ?? null;
            if (is_string($displayName) && trim($displayName) !== '') {
                return trim($displayName);
            }
        }

        return 'Belluga';
    }

    /**
     * @param  array<string, mixed>  $thumb
     * @param  array<string, mixed>  $venue
     */
    private function resolveImageUrl(array $thumb, array $venue): ?string
    {
        foreach ([$thumb['url'] ?? null, $thumb['uri'] ?? null, $venue['hero_image_url'] ?? null, $venue['logo_url'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return preg_match('/^[a-f0-9]{24}$/i', $value) === 1;
    }
}
