<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Events\Concerns\EventManagementPartiesAndMetadata;
use Belluga\Events\Contracts\EventContentSanitizerContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\EventTypeResolverContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
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
        private readonly EventAccountContextResolver $eventAccountContextResolver,
        private readonly EventCapabilitiesService $eventCapabilities,
        private readonly EventOccurrencePayloadSnapshotService $eventOccurrencePayloadSnapshots,
        private readonly EventAggregateWriteService $eventAggregateWrites,
        private readonly EventContentSanitizerContract $contentSanitizer,
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
            $normalized['content'] = $this->contentSanitizer->sanitize(
                $payload['content'] ?? null
            );
            if (strlen($normalized['content']) > InputConstraints::RICH_TEXT_MAX_BYTES) {
                throw ValidationException::withMessages([
                    'content' => ['The content may not be greater than 100 KB after sanitization.'],
                ]);
            }
        }

        if (array_key_exists('type', $payload)) {
            $normalized['type'] = $this->resolveEventTypePayload($payload['type']);
        }

        if (array_key_exists('taxonomy_terms', $payload)) {
            $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForEvent($taxonomyTerms);
                $this->assertTaxonomyTermsAllowedByEventType(
                    $taxonomyTerms,
                    $normalized['type'] ?? $this->resolveExistingEventTypePayload($existing)
                );
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

        $normalized['event_parties'] = $this->resolveEventParties($payload, $existing);
        $normalized['account_context_ids'] = $this->eventAccountContextResolver->resolveForAggregate(
            $this->baseAccountContextIdsForPayload($payload),
            $this->normalizeArray($normalized['event_parties'] ?? []),
            $this->normalizeNullableArray($normalized['place_ref'] ?? ($existing?->place_ref ?? null)),
            $schedule['occurrences']
        );

        return [
            'payload' => $normalized,
            'schedule' => $schedule,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function baseAccountContextIdsForPayload(array $payload): array
    {
        $routeAccountContextId = $this->accountContextIdFromPayload($payload);

        return $routeAccountContextId === null ? [] : [$routeAccountContextId];
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
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOccurrences(mixed $occurrences, array $payload): array
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
                    "occurrences.{$index}.programming_items",
                    $this->accountContextIdFromPayload($payload)
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
     * @return array<int, array<string, mixed>>
     */
    private function resolveProgrammingItems(mixed $items, string $field, ?string $accountContextId): array
    {
        if ($items === null || $items === []) {
            return [];
        }

        if (! is_array($items)) {
            throw ValidationException::withMessages([
                $field => ['programming_items must be an array.'],
            ]);
        }

        $drafts = [];
        $allProfileIds = [];
        $placeRefsById = [];
        $placeRefFieldsById = [];

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

            if (count($profileIds) > 1 && $title === '') {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.title" => ['title is required when more than one linked Account Profile is selected.'],
                ]);
            }

            foreach ($profileIds as $profileId) {
                $allProfileIds[$profileId] = $profileId;
            }
            if ($placeRef !== null) {
                $placeId = (string) $placeRef['id'];
                $placeRefsById[$placeId] = $placeRef;
                $placeRefFieldsById[$placeId] = "{$field}.{$index}.place_ref.id";
            }

            $drafts[] = [
                'time' => $time,
                'title' => $title === '' ? null : $title,
                'account_profile_ids' => $profileIds,
                'place_ref' => $placeRef,
            ];
        }

        $linkedProfilesById = $this->resolveProgrammingLinkedProfileMap(array_values($allProfileIds));
        $locationProfilesById = $this->resolveProgrammingLocationProfileMap(
            $placeRefsById,
            $placeRefFieldsById,
            $accountContextId
        );

        $normalized = [];
        foreach ($drafts as $draft) {
            $placeId = is_array($draft['place_ref'] ?? null)
                ? (string) $draft['place_ref']['id']
                : null;

            $normalized[] = [
                ...$draft,
                'linked_account_profiles' => $this->linkedProfilesForIds(
                    $draft['account_profile_ids'],
                    $linkedProfilesById
                ),
                'location_profile' => $placeId === null
                    ? null
                    : ($locationProfilesById[$placeId] ?? null),
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
        $profilesById = $this->resolveProgrammingLocationProfileMap(
            [(string) $placeRef['id'] => $placeRef],
            [(string) $placeRef['id'] => $field],
            null,
        );

        return $profilesById[(string) $placeRef['id']] ?? [];
    }

    /**
     * @param  array<string, array{type: string, id: string}>  $placeRefsById
     * @param  array<string, string>  $fieldsById
     * @return array<string, array<string, mixed>>
     */
    private function resolveProgrammingLocationProfileMap(array $placeRefsById, array $fieldsById, ?string $accountContextId): array
    {
        if ($placeRefsById === []) {
            return [];
        }

        try {
            $resolved = $this->eventProfileResolver->resolvePhysicalHostsByProfileIds(array_keys($placeRefsById));
        } catch (ValidationException $exception) {
            $firstField = reset($fieldsById) ?: 'programming_items.place_ref.id';
            throw ValidationException::withMessages([
                $firstField => $exception->errors()['place_ref.id'] ?? ['Programming location account profile is invalid.'],
            ]);
        }

        $profilesById = [];
        foreach ($placeRefsById as $profileId => $_placeRef) {
            if ($accountContextId !== null && ! $this->eventProfileResolver->accountOwnsProfile($accountContextId, $profileId)) {
                $field = $fieldsById[$profileId] ?? 'programming_items.place_ref.id';
                throw ValidationException::withMessages([
                    $field => ['Programming location physical host must belong to the target account context.'],
                ]);
            }
            $profilesById[$profileId] = $this->normalizeArray($resolved[$profileId]['venue'] ?? []);
        }

        return $profilesById;
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
        return $this->linkedProfilesForIds(
            $profileIds,
            $this->resolveProgrammingLinkedProfileMap($profileIds)
        );
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    private function resolveProgrammingLinkedProfileMap(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $resolved = $this->eventProfileResolver->resolveEventPartyProfilesByIds(array_values(array_unique($profileIds)));
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

        return $profilesById;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @param  array<string, array<string, mixed>>  $profilesById
     * @return array<int, array<string, mixed>>
     */
    private function linkedProfilesForIds(array $profileIds, array $profilesById): array
    {
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
            'allowed_taxonomies' => $this->normalizeStringList($resolved['allowed_taxonomies'] ?? []),
            'icon' => $resolved['icon'] ?? null,
            'color' => $resolved['color'] ?? null,
            'icon_color' => $resolved['icon_color'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveExistingEventTypePayload(?Event $existing): ?array
    {
        if ($existing === null) {
            return null;
        }

        $existingType = $this->normalizeArray($existing->type ?? null);
        $id = trim((string) ($existingType['id'] ?? ''));
        if ($id === '') {
            return $existingType === [] ? null : $existingType;
        }

        $resolved = $this->eventTypeResolver->resolveById($id);

        return is_array($resolved) && $resolved !== [] ? $resolved : $existingType;
    }

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @param  array<string, mixed>|null  $eventType
     */
    private function assertTaxonomyTermsAllowedByEventType(array $terms, ?array $eventType): void
    {
        $allowedTaxonomies = $this->normalizeStringList($eventType['allowed_taxonomies'] ?? []);
        $types = $this->extractTaxonomyTypes($terms);

        $invalid = array_values(array_diff($types, $allowedTaxonomies));
        if ($invalid === []) {
            return;
        }

        throw ValidationException::withMessages([
            'taxonomy_terms' => ['Some taxonomy types are not allowed for this event type.'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, string>
     */
    private function extractTaxonomyTypes(array $terms): array
    {
        $types = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $type = trim((string) ($term['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $types[] = $type;
        }

        return array_values(array_unique($types));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            $value
        ))));
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
        $accountContextId = $this->accountContextIdFromPayload($payload) ?? '';

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function accountContextIdFromPayload(array $payload): ?string
    {
        $accountContextId = isset($payload['_account_context_id'])
            ? trim((string) $payload['_account_context_id'])
            : '';

        return $accountContextId === '' ? null : $accountContextId;
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
