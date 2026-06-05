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
     * @param  array{event_id:string,occurrence_id:string}  $targetRef
     * @return array{
     *     target_ref: array{event_id:string,occurrence_id:string},
     *     event_snapshot: array{
     *         event_name:string,
     *         event_slug:string,
     *         event_date:?Carbon,
     *         event_image_url:?string,
     *         location:string,
     *         host_name:string,
     *         tags:array<int,string>,
     *         linked_account_profiles:array<int,array<string,mixed>>,
     *         profile_groups:array<int,array<string,mixed>>,
     *         venue_account_profile_id:?string,
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
        if ($occurrenceRef === '') {
            throw new InviteDomainException(
                errorCode: 'target_occurrence_required',
                httpStatus: 422,
                message: 'occurrence_id is required for invite targets.'
            );
        }

        $event = $this->targetRead->findEventByIdOrSlug($eventRef);
        if (! $event) {
            throw new InviteDomainException('target_not_found', 404);
        }

        $occurrence = $this->targetRead->findOccurrenceForEvent((string) $event['id'], $occurrenceRef);
        if (! $occurrence) {
            throw new InviteDomainException('target_not_found', 404);
        }

        $this->assertPublished($event, $occurrence);

        $eventDate = $this->normalizeCarbon($occurrence['starts_at'] ?? null)
            ?? $this->normalizeCarbon($event['date_time_start'] ?? null);
        $expiresAt = $this->normalizeCarbon($occurrence['ends_at'] ?? null)
            ?? $this->normalizeCarbon($event['date_time_end'] ?? null);

        $eventPayload = $this->normalizeArray($event['attributes'] ?? []);
        $occurrencePayload = $occurrence ? $this->normalizeArray($occurrence['attributes'] ?? []) : [];
        $detailProjection = $this->normalizeArray(
            $this->targetRead->findEventDetailProjection(
                (string) $event['id'],
                (string) $occurrence['id'],
            ) ?? []
        );
        $location = $this->resolveLocationLabel($eventPayload, $detailProjection);
        $hostName = $this->resolveHostName($eventPayload, $detailProjection);

        return [
            'target_ref' => [
                'event_id' => (string) $event['id'],
                'occurrence_id' => (string) $occurrence['id'],
            ],
            'event_snapshot' => [
                'event_name' => (string) ($detailProjection['title'] ?? $event['title'] ?? ''),
                'event_slug' => (string) ($detailProjection['slug'] ?? $event['slug'] ?? ''),
                'event_date' => $eventDate,
                'event_image_url' => $this->normalizeOptionalString(
                    $detailProjection['hero_image_url'] ?? $event['event_image_url'] ?? null,
                ),
                'location' => $location,
                'host_name' => $hostName,
                'tags' => array_values(array_map(
                    'strval',
                    $this->normalizeList($detailProjection['tags'] ?? $eventPayload['tags'] ?? []),
                )),
                'linked_account_profiles' => $this->normalizeListOfMaps(
                    $detailProjection['linked_account_profiles'] ?? [],
                ),
                'profile_groups' => $this->normalizeListOfMaps(
                    $detailProjection['profile_groups'] ?? [],
                ),
                'venue_account_profile_id' => $this->resolveVenueAccountProfileId(
                    $detailProjection,
                    $eventPayload,
                ),
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

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return array_values($value->getArrayCopy());
        }
        if (is_array($value)) {
            return array_values($value);
        }
        if ($value instanceof \Traversable) {
            return array_values(iterator_to_array($value));
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeListOfMaps(mixed $value): array
    {
        $items = [];
        foreach ($this->normalizeList($value) as $item) {
            $normalized = $this->normalizeArray($item);
            if ($normalized === []) {
                continue;
            }
            $items[] = $normalized;
        }

        return $items;
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
     * @param  array<string, mixed>  $detailProjection
     */
    private function resolveLocationLabel(array $eventPayload, array $detailProjection = []): string
    {
        $detailLocation = $detailProjection['location'] ?? null;
        if (is_string($detailLocation) && trim($detailLocation) !== '') {
            return trim($detailLocation);
        }

        $detailLocationPayload = $this->normalizeArray($detailLocation);
        $detailVenue = $this->normalizeArray($detailProjection['venue'] ?? []);
        $location = $this->normalizeArray($eventPayload['location'] ?? []);
        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);

        foreach ([
            $detailLocationPayload['display_name'] ?? null,
            $detailLocationPayload['name'] ?? null,
            $detailLocationPayload['label'] ?? null,
            $detailLocationPayload['address'] ?? null,
            $detailVenue['display_name'] ?? null,
            $detailVenue['name'] ?? null,
            $location['label'] ?? null,
            $location['name'] ?? null,
            $venue['display_name'] ?? null,
            $venue['name'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Belluga Event';
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     * @param  array<string, mixed>  $detailProjection
     */
    private function resolveHostName(array $eventPayload, array $detailProjection = []): string
    {
        $detailVenue = $this->normalizeArray($detailProjection['venue'] ?? []);
        foreach ([$detailVenue['display_name'] ?? null, $detailVenue['name'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($this->normalizeListOfMaps($detailProjection['linked_account_profiles'] ?? []) as $profile) {
            foreach ([$profile['display_name'] ?? null, $profile['name'] ?? null] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);
        if (isset($venue['display_name']) && is_string($venue['display_name']) && trim($venue['display_name']) !== '') {
            return trim($venue['display_name']);
        }

        $eventParties = $this->normalizeList($eventPayload['event_parties'] ?? []);
        foreach ($eventParties as $party) {
            $payload = $this->normalizeArray($party);
            $metadata = $this->normalizeArray($payload['metadata'] ?? []);
            foreach ([
                $payload['display_name'] ?? null,
                $payload['name'] ?? null,
                $metadata['display_name'] ?? null,
                $metadata['name'] ?? null,
            ] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return 'Belluga';
    }

    /**
     * @param  array<string, mixed>  $detailProjection
     * @param  array<string, mixed>  $eventPayload
     */
    private function resolveVenueAccountProfileId(array $detailProjection, array $eventPayload): ?string
    {
        $detailVenue = $this->normalizeArray($detailProjection['venue'] ?? []);
        $eventVenue = $this->normalizeArray($eventPayload['venue'] ?? []);
        $placeRef = $this->normalizeArray($eventPayload['place_ref'] ?? []);

        foreach ([
            $detailVenue['id'] ?? null,
            $eventVenue['id'] ?? null,
            $placeRef['type'] === 'account_profile' ? ($placeRef['id'] ?? null) : null,
        ] as $candidate) {
            $normalized = $this->normalizeOptionalString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
