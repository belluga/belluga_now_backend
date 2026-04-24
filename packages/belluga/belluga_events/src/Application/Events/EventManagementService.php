<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Events\Concerns\EventManagementPartiesAndMetadata;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\EventTypeResolverContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Support\EventContentHtmlSanitizer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

class EventManagementService
{
    use EventManagementPartiesAndMetadata;

    public function __construct(
        private readonly EventTaxonomyValidationContract $taxonomyValidationService,
        private readonly EventTaxonomySnapshotResolverContract $taxonomySnapshotResolver,
        private readonly EventTypeResolverContract $eventTypeResolver,
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventPartyMapperRegistryContract $eventPartyMappers,
        private readonly EventCapabilitiesService $eventCapabilities,
        private readonly EventOccurrencePayloadSnapshotService $eventOccurrencePayloadSnapshots,
        private readonly EventAggregateWriteService $eventAggregateWrites,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Event
    {
        $startedAt = microtime(true);
        $normalized = $this->normalizePayloadAndSchedule($payload, null);

        $event = $this->eventAggregateWrites->create(
            $normalized['payload'],
            $normalized['schedule']['occurrences'],
        );

        $this->events->dispatch(new EventCreated((string) $event->_id));
        $this->logWriteCompleted('create', $event, count($normalized['schedule']['occurrences']), $startedAt);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Event $event, array $payload): Event
    {
        $startedAt = microtime(true);
        $normalized = $this->normalizePayloadAndSchedule($payload, $event);

        $updated = $this->eventAggregateWrites->update(
            $event,
            $normalized['payload'],
            $normalized['schedule']['occurrences'],
        );

        $this->events->dispatch(new EventUpdated((string) $updated->_id));
        $this->logWriteCompleted('update', $updated, count($normalized['schedule']['occurrences']), $startedAt);

        return $updated;
    }

    public function delete(Event $event): void
    {
        $startedAt = microtime(true);
        $eventId = (string) $event->_id;

        $this->eventAggregateWrites->delete($event);

        $this->events->dispatch(new EventDeleted($eventId));
        $this->logDeleteCompleted($event, $eventId, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   payload: array<string, mixed>,
     *   schedule: array{
     *     touched: bool,
     *     occurrences: array<int, array<string, mixed>>,
     *     date_time_start: Carbon|null,
     *     date_time_end: Carbon|null
     *   }
     * }
     */
    private function normalizePayloadAndSchedule(array $payload, ?Event $existing): array
    {
        $normalized = [];

        foreach ([
            'title',
            'thumb',
            'tags',
            'categories',
            'taxonomy_terms',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalized[$field] = $payload[$field];
            }
        }

        if (array_key_exists('content', $payload)) {
            $normalized['content'] = EventContentHtmlSanitizer::sanitize(
                $payload['content'] ?? null
            );
        }

        if (array_key_exists('type', $payload)) {
            $normalized['type'] = $this->resolveEventTypePayload($payload['type']);
        }

        if (array_key_exists('taxonomy_terms', $payload)) {
            $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForEvent($taxonomyTerms);
                $normalized['taxonomy_terms'] = $this->taxonomySnapshotResolver->resolve($taxonomyTerms);
            } else {
                $normalized['taxonomy_terms'] = [];
            }
        }

        $resolvedCapabilities = $this->eventCapabilities->resolveEventCapabilities($payload, $existing);
        $schedule = $this->resolveSchedulePayload($payload, $existing);
        if ($schedule['touched']) {
            $this->eventCapabilities->assertScheduleConstraints($resolvedCapabilities, $schedule['occurrences']);
        }

        if ($schedule['touched']) {
            $normalized['date_time_start'] = $schedule['date_time_start'];
            $normalized['date_time_end'] = $schedule['date_time_end'];
        }

        if ($this->eventCapabilities->shouldPersistCapabilities($payload, $existing)) {
            $normalized['capabilities'] = $resolvedCapabilities;
        }

        $publication = $payload['publication'] ?? null;
        if ($publication !== null || $existing === null) {
            $normalized['publication'] = $this->resolvePublicationPayload($publication, $existing);
        }

        $resolvedLocation = $this->resolveLocationAndPlacePayload($payload, $existing);
        if ($resolvedLocation['touched']) {
            $normalized['location'] = $resolvedLocation['location'];
            $normalized['place_ref'] = $resolvedLocation['place_ref'];
            $normalized['geo_location'] = $resolvedLocation['geo_location'];
            $normalized['venue'] = $resolvedLocation['venue'];
        }

        if ($existing === null) {
            $normalized['created_by'] = $this->resolveCreatedByPrincipal($payload);
        }

        $eventParties = $this->resolveEventParties($payload, $existing);
        $normalized['event_parties'] = $this->mergeEventPartiesByKey(
            $eventParties,
            $this->resolveProgrammingEventParties($schedule['occurrences'])
        );

        return [
            'payload' => $normalized,
            'schedule' => $schedule,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   touched: bool,
     *   occurrences: array<int, array<string, mixed>>,
     *   date_time_start: Carbon|null,
     *   date_time_end: Carbon|null
     * }
     */
    private function resolveSchedulePayload(array $payload, ?Event $existing): array
    {
        $hasOccurrences = array_key_exists('occurrences', $payload);

        if ($hasOccurrences) {
            $occurrences = $this->normalizeOccurrences($payload['occurrences'], $payload);

            return $this->buildScheduleResult(true, $occurrences);
        }

        if ($existing === null) {
            throw ValidationException::withMessages([
                'occurrences' => ['occurrences is required.'],
            ]);
        }

        $existingOccurrences = $this->eventOccurrencePayloadSnapshots->requireForUpdate($existing);
        $firstOccurrence = $existingOccurrences[0] ?? null;

        return [
            'touched' => false,
            'occurrences' => $existingOccurrences,
            'date_time_start' => $firstOccurrence['date_time_start'] ?? null,
            'date_time_end' => $firstOccurrence['date_time_end'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $rootPayload
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOccurrences(mixed $occurrences, array $rootPayload): array
    {
        if (! is_array($occurrences) || $occurrences === []) {
            throw ValidationException::withMessages([
                'occurrences' => ['At least one occurrence is required.'],
            ]);
        }

        $normalized = [];

        foreach ($occurrences as $index => $occurrence) {
            if (! is_array($occurrence)) {
                throw ValidationException::withMessages([
                    "occurrences.{$index}" => ['Occurrence payload must be an object.'],
                ]);
            }

            $start = $this->normalizeDateValue(
                $occurrence['date_time_start'] ?? null,
                "occurrences.{$index}.date_time_start"
            );

            if (! $start) {
                throw ValidationException::withMessages([
                    "occurrences.{$index}.date_time_start" => ['date_time_start is required for each occurrence.'],
                ]);
            }

            $end = array_key_exists('date_time_end', $occurrence)
                ? $this->normalizeDateValue($occurrence['date_time_end'], "occurrences.{$index}.date_time_end")
                : null;

            $this->assertOccurrenceBounds($start, $end, "occurrences.{$index}.date_time_end");

            $ownEventParties = array_key_exists('event_parties', $occurrence)
                ? $this->resolveEventParties(['event_parties' => $occurrence['event_parties']], null)
                : [];
            if (array_key_exists('location', $occurrence) || array_key_exists('place_ref', $occurrence)) {
                throw ValidationException::withMessages([
                    "occurrences.{$index}.location" => ['Occurrences do not accept location overrides. Use event location or programming item place_ref.'],
                ]);
            }

            $normalized[] = [
                'date_time_start' => $start,
                'date_time_end' => $end,
                'event_parties' => $ownEventParties,
                'has_location_override' => false,
                'location_override' => null,
                'programming_items' => $this->resolveProgrammingItems(
                    $occurrence['programming_items'] ?? [],
                    "occurrences.{$index}.programming_items"
                ),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return $left['date_time_start']->getTimestamp() <=> $right['date_time_start']->getTimestamp();
        });

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array{
     *   touched: bool,
     *   occurrences: array<int, array<string, mixed>>,
     *   date_time_start: Carbon|null,
     *   date_time_end: Carbon|null
     * }
     */
    private function buildScheduleResult(bool $touched, array $occurrences): array
    {
        $first = $occurrences[0] ?? null;

        return [
            'touched' => $touched,
            'occurrences' => $occurrences,
            'date_time_start' => $first['date_time_start'] ?? null,
            'date_time_end' => $first['date_time_end'] ?? null,
        ];
    }

    private function assertOccurrenceBounds(Carbon $start, ?Carbon $end, string $endField): void
    {
        if ($end !== null && $end->lessThan($start)) {
            throw ValidationException::withMessages([
                $endField => ['date_time_end must be greater than or equal to date_time_start.'],
            ]);
        }
    }

    private function normalizeDateValue(mixed $value, string $field): ?Carbon
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
                throw ValidationException::withMessages([
                    $field => ['Invalid date value.'],
                ]);
            }
        }

        throw ValidationException::withMessages([
            $field => ['Invalid date value.'],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $publication
     * @return array<string, mixed>
     */
    private function resolvePublicationPayload(?array $publication, ?Event $existing): array
    {
        $current = $existing?->publication ?? [];
        $current = is_array($current) ? $current : [];

        $status = $publication['status'] ?? $current['status'] ?? 'draft';
        $publishAt = $publication['publish_at'] ?? $current['publish_at'] ?? null;

        if (! in_array($status, ['published', 'publish_scheduled', 'draft', 'ended'], true)) {
            throw ValidationException::withMessages([
                'publication.status' => ['Invalid publication status.'],
            ]);
        }

        $publishAt = $this->normalizePublishAt($publishAt);

        if ($status === 'publish_scheduled' && ! $publishAt) {
            throw ValidationException::withMessages([
                'publication.publish_at' => ['publish_at is required for publish_scheduled status.'],
            ]);
        }

        if ($status === 'published' && ! $publishAt) {
            $publishAt = Carbon::now();
        }

        return [
            'status' => $status,
            'publish_at' => $publishAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   touched: bool,
     *   location: array<string, mixed>,
     *   place_ref: array<string, mixed>|null,
     *   geo_location: array<string, mixed>|null,
     *   venue: array<string, mixed>
     * }
     */
    private function resolveLocationAndPlacePayload(array $payload, ?Event $existing): array
    {
        $touched = array_key_exists('location', $payload) || array_key_exists('place_ref', $payload) || $existing === null;

        if (! $touched) {
            return [
                'touched' => false,
                'location' => $this->normalizeArray($existing?->location ?? []),
                'place_ref' => $this->normalizeNullableArray($existing?->place_ref ?? null),
                'geo_location' => $this->normalizeGeoLocation($existing?->geo_location ?? null, null),
                'venue' => $this->normalizeArray($existing?->venue ?? []),
            ];
        }

        $locationPayload = array_key_exists('location', $payload)
            ? $payload['location']
            : ($existing?->location ?? null);
        if (! is_array($locationPayload)) {
            throw ValidationException::withMessages([
                'location' => ['location payload is required.'],
            ]);
        }

        $mode = trim((string) ($locationPayload['mode'] ?? ''));
        if (! in_array($mode, ['physical', 'online', 'hybrid'], true)) {
            throw ValidationException::withMessages([
                'location.mode' => ['location.mode must be one of physical, online or hybrid.'],
            ]);
        }

        $placeRefSource = array_key_exists('place_ref', $payload)
            ? $payload['place_ref']
            : ($existing?->place_ref ?? null);
        $placeRef = $this->normalizePlaceRef($placeRefSource);
        if (in_array($mode, ['physical', 'hybrid'], true) && $placeRef === null) {
            throw ValidationException::withMessages([
                'place_ref' => ['place_ref is required when location.mode is physical or hybrid.'],
            ]);
        }

        $onlinePayload = null;
        if (in_array($mode, ['online', 'hybrid'], true)) {
            $onlineSource = $locationPayload['online'] ?? null;
            if (! is_array($onlineSource)) {
                throw ValidationException::withMessages([
                    'location.online' => ['location.online is required when location.mode is online or hybrid.'],
                ]);
            }
            $onlinePayload = $this->normalizeOnlineLocation($onlineSource);
        }

        $venue = [];
        $geoLocation = $this->normalizeGeoLocation($locationPayload['geo'] ?? null, 'location.geo');

        if (is_array($placeRef) && ($placeRef['type'] ?? null) === 'account_profile') {
            $resolvedVenue = $this->eventProfileResolver->resolvePhysicalHostByProfileId((string) $placeRef['id']);
            $this->assertVenueBelongsToAccountContext($payload, $resolvedVenue);
            $venue = $this->normalizeArray($resolvedVenue['venue'] ?? []);
            $geoLocation = $this->normalizeGeoLocation($resolvedVenue['location'] ?? null, 'place_ref.id');
        }

        if (in_array($mode, ['physical', 'hybrid'], true) && $geoLocation === null) {
            throw ValidationException::withMessages([
                'location.geo' => ['A physical location with valid coordinates is required.'],
            ]);
        }

        $location = [
            'mode' => $mode,
        ];
        if ($geoLocation !== null) {
            $location['geo'] = $geoLocation;
        }
        if ($onlinePayload !== null) {
            $location['online'] = $onlinePayload;
        }

        return [
            'touched' => true,
            'location' => $location,
            'place_ref' => $placeRef,
            'geo_location' => $geoLocation,
            'venue' => $venue,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePlaceRef(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'place_ref' => ['place_ref payload must be an object.'],
            ]);
        }

        $type = trim((string) ($value['type'] ?? ''));
        $id = trim((string) ($value['id'] ?? ''));
        if ($type === '' || $id === '') {
            throw ValidationException::withMessages([
                'place_ref' => ['place_ref.type and place_ref.id are required.'],
            ]);
        }
        if ($type !== 'account_profile') {
            throw ValidationException::withMessages([
                'place_ref.type' => ['place_ref.type must be account_profile.'],
            ]);
        }

        $normalized = [
            'type' => $type,
            'id' => $id,
        ];

        if (isset($value['metadata']) && is_array($value['metadata']) && $value['metadata'] !== []) {
            $normalized['metadata'] = $value['metadata'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @param  array<string, mixed>  $rootPayload
     * @return array{
     *   location: array<string, mixed>,
     *   place_ref: array<string, mixed>|null,
     *   geo_location: array<string, mixed>|null,
     *   venue: array<string, mixed>
     * }|null
     */
    private function resolveOccurrenceLocationOverride(array $occurrence, array $rootPayload): ?array
    {
        if (! array_key_exists('location', $occurrence) && ! array_key_exists('place_ref', $occurrence)) {
            return null;
        }

        $payload = [];
        if (array_key_exists('location', $occurrence)) {
            $payload['location'] = $occurrence['location'];
        }
        if (array_key_exists('place_ref', $occurrence)) {
            $payload['place_ref'] = $occurrence['place_ref'];
        }
        if (array_key_exists('_account_context_id', $rootPayload)) {
            $payload['_account_context_id'] = $rootPayload['_account_context_id'];
        }

        $resolved = $this->resolveLocationAndPlacePayload($payload, null);

        return [
            'location' => $resolved['location'],
            'place_ref' => $resolved['place_ref'],
            'geo_location' => $resolved['geo_location'],
            'venue' => $resolved['venue'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveProgrammingItems(mixed $items, string $field): array
    {
        if ($items === null || $items === []) {
            return [];
        }

        if (! is_array($items)) {
            throw ValidationException::withMessages([
                $field => ['programming_items must be an array.'],
            ]);
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}" => ['programming item payload must be an object.'],
                ]);
            }

            $time = trim((string) ($item['time'] ?? ''));
            if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.time" => ['time must use HH:MM format.'],
                ]);
            }

            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            $profileIds = $this->normalizeProgrammingProfileIds(
                $item['account_profile_ids'] ?? [],
                "{$field}.{$index}.account_profile_ids"
            );
            $placeRef = $this->normalizeProgrammingPlaceRef(
                $item['place_ref'] ?? null,
                "{$field}.{$index}.place_ref"
            );
            $locationProfile = $placeRef === null
                ? null
                : $this->resolveProgrammingLocationProfile(
                    $placeRef,
                    "{$field}.{$index}.place_ref.id"
                );

            if (count($profileIds) > 1 && $title === '') {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.title" => ['title is required when more than one linked Account Profile is selected.'],
                ]);
            }

            $normalized[] = [
                'time' => $time,
                'title' => $title === '' ? null : $title,
                'account_profile_ids' => $profileIds,
                'linked_account_profiles' => $this->resolveProgrammingLinkedProfiles($profileIds),
                'place_ref' => $placeRef,
                'location_profile' => $locationProfile,
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);

        return $normalized;
    }

    /**
     * @return array{type: string, id: string}|null
     */
    private function normalizeProgrammingPlaceRef(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw ValidationException::withMessages([
                $field => ['place_ref payload must be an object.'],
            ]);
        }

        $type = trim((string) ($value['type'] ?? ''));
        $id = trim((string) ($value['id'] ?? ''));
        if ($type === '' || $id === '') {
            throw ValidationException::withMessages([
                $field => ['place_ref.type and place_ref.id are required.'],
            ]);
        }
        if ($type !== 'account_profile') {
            throw ValidationException::withMessages([
                "{$field}.type" => ['place_ref.type must be account_profile.'],
            ]);
        }

        return [
            'type' => $type,
            'id' => $id,
        ];
    }

    /**
     * @param  array{type: string, id: string}  $placeRef
     * @return array<string, mixed>
     */
    private function resolveProgrammingLocationProfile(array $placeRef, string $field): array
    {
        try {
            $resolved = $this->eventProfileResolver->resolvePhysicalHostByProfileId((string) $placeRef['id']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                $field => $exception->errors()['place_ref.id'] ?? ['Programming location account profile is invalid.'],
            ]);
        }

        return $this->normalizeArray($resolved['venue'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    private function resolveProgrammingEventParties(array $occurrences): array
    {
        $profileIds = [];
        foreach ($occurrences as $occurrence) {
            foreach ($this->normalizeArray($occurrence['programming_items'] ?? []) as $item) {
                $programmingItem = $this->normalizeArray($item);
                foreach ($this->normalizeArray($programmingItem['account_profile_ids'] ?? []) as $profileId) {
                    $normalizedProfileId = trim((string) $profileId);
                    if ($normalizedProfileId !== '' && ! in_array($normalizedProfileId, $profileIds, true)) {
                        $profileIds[] = $normalizedProfileId;
                    }
                }
            }
        }

        if ($profileIds === []) {
            return [];
        }

        return $this->resolveEventParties([
            'event_parties' => array_map(
                static fn (string $profileId): array => [
                    'party_ref_id' => $profileId,
                ],
                $profileIds
            ),
        ], null);
    }

    /**
     * @param  array<int, array<string, mixed>>  $base
     * @param  array<int, array<string, mixed>>  $additional
     * @return array<int, array<string, mixed>>
     */
    private function mergeEventPartiesByKey(array $base, array $additional): array
    {
        $merged = [];
        $seen = [];

        foreach ([$base, $additional] as $rows) {
            foreach ($rows as $row) {
                $partyType = trim((string) ($row['party_type'] ?? ''));
                $partyRefId = trim((string) ($row['party_ref_id'] ?? ''));
                if ($partyType === '' || $partyRefId === '') {
                    continue;
                }

                $key = $this->eventPartyKey($partyType, $partyRefId);
                if (isset($seen[$key])) {
                    continue;
                }

                $merged[] = $row;
                $seen[$key] = true;
            }
        }

        return $merged;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeProgrammingProfileIds(mixed $items, string $field): array
    {
        if ($items === null || $items === []) {
            return [];
        }

        if (! is_array($items)) {
            throw ValidationException::withMessages([
                $field => ['account_profile_ids must be an array.'],
            ]);
        }

        $ids = [];
        foreach ($items as $index => $item) {
            $id = trim((string) $item);
            if ($id === '') {
                throw ValidationException::withMessages([
                    "{$field}.{$index}" => ['account_profile_id is required.'],
                ]);
            }

            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveProgrammingLinkedProfiles(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $resolved = $this->eventProfileResolver->resolveEventPartyProfilesByIds($profileIds);
        $profilesById = [];
        foreach ($resolved as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $id = trim((string) ($profile['id'] ?? ''));
            if ($id !== '') {
                $profilesById[$id] = $profile;
            }
        }

        $profiles = [];
        foreach ($profileIds as $profileId) {
            $profile = $profilesById[$profileId] ?? null;
            if (! is_array($profile)) {
                continue;
            }

            $profiles[] = [
                'id' => $profileId,
                'display_name' => trim((string) ($profile['display_name'] ?? '')),
                'slug' => isset($profile['slug']) ? (string) $profile['slug'] : null,
                'profile_type' => isset($profile['profile_type']) ? (string) $profile['profile_type'] : '',
                'avatar_url' => $profile['avatar_url'] ?? null,
                'cover_url' => $profile['cover_url'] ?? null,
                'taxonomy_terms' => $this->taxonomySnapshotResolver->ensureSnapshots(
                    is_array($profile['taxonomy_terms'] ?? null) ? $profile['taxonomy_terms'] : []
                ),
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEventTypePayload(mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'type' => ['type payload must be an object.'],
            ]);
        }

        $id = trim((string) ($value['id'] ?? ''));
        if ($id === '') {
            throw ValidationException::withMessages([
                'type.id' => ['type.id is required.'],
            ]);
        }

        $resolved = $this->eventTypeResolver->resolveById($id);
        if (! is_array($resolved) || $resolved === []) {
            throw ValidationException::withMessages([
                'type.id' => ['Event type not found for this tenant.'],
            ]);
        }

        $name = trim((string) ($resolved['name'] ?? ''));
        $slug = trim((string) ($resolved['slug'] ?? ''));
        $description = trim((string) ($resolved['description'] ?? ''));
        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages([
                'type.id' => ['Resolved event type payload is invalid.'],
            ]);
        }

        return [
            'id' => (string) ($resolved['id'] ?? $id),
            'name' => $name,
            'slug' => $slug,
            'description' => $description === '' ? null : $description,
            'visual' => is_array($resolved['visual'] ?? null)
                ? $resolved['visual']
                : (is_array($resolved['poi_visual'] ?? null) ? $resolved['poi_visual'] : null),
            'icon' => $resolved['icon'] ?? null,
            'color' => $resolved['color'] ?? null,
            'icon_color' => $resolved['icon_color'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeNullableArray(mixed $value): ?array
    {
        $normalized = $this->normalizeArray($value);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function normalizeOnlineLocation(array $value): array
    {
        $url = trim((string) ($value['url'] ?? ''));
        if ($url === '') {
            throw ValidationException::withMessages([
                'location.online.url' => ['location.online.url is required for online/hybrid events.'],
            ]);
        }

        $normalized = [
            'url' => $url,
        ];

        if (isset($value['platform']) && trim((string) $value['platform']) !== '') {
            $normalized['platform'] = trim((string) $value['platform']);
        }
        if (isset($value['label']) && trim((string) $value['label']) !== '') {
            $normalized['label'] = trim((string) $value['label']);
        }

        return $normalized;
    }

    /**
     * @return array{type: string, coordinates: array{0: float, 1: float}}|null
     */
    private function normalizeGeoLocation(mixed $value, ?string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            if ($field !== null) {
                throw ValidationException::withMessages([
                    $field => ['Geo location payload must be an object.'],
                ]);
            }

            return null;
        }

        $coordinates = $value['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            if ($field !== null) {
                throw ValidationException::withMessages([
                    $field => ['Geo coordinates are required.'],
                ]);
            }

            return null;
        }

        return [
            'type' => 'Point',
            'coordinates' => [
                (float) $coordinates[0],
                (float) $coordinates[1],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{venue: array<string, mixed>, location: array<string, mixed>}  $resolvedVenue
     */
    private function assertVenueBelongsToAccountContext(array $payload, array $resolvedVenue): void
    {
        $accountContextId = isset($payload['_account_context_id'])
            ? (string) $payload['_account_context_id']
            : '';

        if ($accountContextId === '') {
            return;
        }

        $venueId = isset($resolvedVenue['venue']['id']) ? (string) $resolvedVenue['venue']['id'] : '';
        if ($venueId === '' || ! $this->eventProfileResolver->accountOwnsProfile($accountContextId, $venueId)) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host must belong to the target account context.'],
            ]);
        }
    }

    private function logWriteCompleted(string $operation, Event $event, int $occurrenceCount, float $startedAt): void
    {
        $publication = is_array($event->publication ?? null)
            ? $event->publication
            : (array) ($event->publication ?? []);

        Log::info('events_write_completed', [
            'operation' => $operation,
            'event_id' => (string) ($event->_id ?? ''),
            'occurrence_count' => max(0, $occurrenceCount),
            'publication_status' => (string) ($publication['status'] ?? 'draft'),
            'publication_publish_at' => $this->formatDate($publication['publish_at'] ?? null),
            'duration_ms' => $this->durationMs($startedAt),
        ]);
    }

    private function logDeleteCompleted(Event $event, string $eventId, float $startedAt): void
    {
        $publication = is_array($event->publication ?? null)
            ? $event->publication
            : (array) ($event->publication ?? []);

        $occurrenceCount = EventOccurrence::withTrashed()
            ->where('event_id', $eventId)
            ->count();

        Log::info('events_write_completed', [
            'operation' => 'delete',
            'event_id' => $eventId,
            'occurrence_count' => max(0, (int) $occurrenceCount),
            'publication_status' => (string) ($publication['status'] ?? 'draft'),
            'publication_publish_at' => $this->formatDate($publication['publish_at'] ?? null),
            'duration_ms' => $this->durationMs($startedAt),
        ]);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format(DATE_ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
