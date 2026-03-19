<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Targets;

use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class InviteTargetResolverService
{
    public function __construct(
        private readonly InviteRuntimeSettingsService $runtimeSettings,
        private readonly InviteTargetReadContract $targetRead,
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

        $event = $this->targetRead->findEventByIdOrSlug($eventRef);
        if (! $event) {
            throw new InviteDomainException('target_not_found', 404);
        }

        $occurrence = $occurrenceRef !== ''
            ? $this->targetRead->findOccurrenceForEvent((string) $event['id'], $occurrenceRef)
            : null;

        if ($occurrenceRef !== '' && ! $occurrence) {
            throw new InviteDomainException('target_not_found', 404);
        }

        if ($occurrence === null && $this->targetRead->countOccurrencesForEvent((string) $event['id'], 2) > 1) {
            throw new InviteDomainException(
                errorCode: 'target_occurrence_required',
                httpStatus: 422,
                message: 'occurrence_id is required for multi-occurrence events.'
            );
        }

        $this->assertPublished($event, $occurrence);

        $eventDate = $this->normalizeCarbon($occurrence['starts_at'] ?? null)
            ?? $this->normalizeCarbon($event['date_time_start'] ?? null);
        $expiresAt = $this->normalizeCarbon($occurrence['ends_at'] ?? null)
            ?? $this->normalizeCarbon($event['date_time_end'] ?? null);

        $eventPayload = $this->normalizeArray($event['attributes'] ?? []);
        $occurrencePayload = $occurrence ? $this->normalizeArray($occurrence['attributes'] ?? []) : [];
        $location = $this->resolveLocationLabel($eventPayload);
        $hostName = $this->resolveHostName($eventPayload);
        $thumb = $this->normalizeArray($eventPayload['thumb'] ?? []);
        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);

        return [
            'target_ref' => [
                'event_id' => (string) $event['id'],
                'occurrence_id' => $occurrence ? (string) $occurrence['id'] : null,
            ],
            'event_snapshot' => [
                'event_name' => (string) ($event['title'] ?? ''),
                'event_slug' => (string) ($event['slug'] ?? ''),
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

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,mixed>|null  $occurrence
     */
    private function assertPublished(array $event, ?array $occurrence): void
    {
        $publication = $this->normalizeArray($event['publication'] ?? []);
        $status = (string) ($publication['status'] ?? 'draft');
        $publishAt = $this->normalizeCarbon($publication['publish_at'] ?? null);

        if ($status !== 'published' || ($publishAt instanceof Carbon && $publishAt->isFuture())) {
            throw new InviteDomainException('target_not_available', 404);
        }

        if ($occurrence !== null && ! (bool) ($occurrence['is_event_published'] ?? false)) {
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

    private function normalizeCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return null;
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
}
