<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Events\Concerns\EventManagementPartiesAndMetadata;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\EventTypeResolverContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

class EventManagementService
{
    use EventManagementPartiesAndMetadata;

    public function __construct(
        private readonly EventTaxonomyValidationContract $taxonomyValidationService,
        private readonly EventTypeResolverContract $eventTypeResolver,
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventPartyMapperRegistryContract $eventPartyMappers,
        private readonly EventCapabilitiesService $eventCapabilities,
        private readonly EventOccurrenceSyncService $eventOccurrenceSyncService,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Event
    {
        $startedAt = microtime(true);
        $normalized = $this->normalizePayloadAndSchedule($payload, null);

        /** @var Event $event */
        $event = $this->runTenantTransaction(function () use ($normalized): Event {
            $created = Event::query()->create($normalized['payload']);
            $this->eventOccurrenceSyncService->syncFromEvent($created, $normalized['schedule']['occurrences']);

            return $created->fresh();
        });

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

        /** @var Event $updated */
        $updated = $this->runTenantTransaction(function () use ($event, $normalized): Event {
            $event->fill($normalized['payload']);
            $event->save();

            $fresh = $event->fresh();
            $this->eventOccurrenceSyncService->syncFromEvent($fresh, $normalized['schedule']['occurrences']);

            return $fresh;
        });

        $this->events->dispatch(new EventUpdated((string) $updated->_id));
        $this->logWriteCompleted('update', $updated, count($normalized['schedule']['occurrences']), $startedAt);

        return $updated;
    }

    public function delete(Event $event): void
    {
        $startedAt = microtime(true);
        $eventId = (string) $event->_id;

        $this->runTenantTransaction(function () use ($event, $eventId): null {
            $event->delete();
            $this->eventOccurrenceSyncService->softDeleteByEventId($eventId);

            return null;
        });

        $this->events->dispatch(new EventDeleted($eventId));
        $this->logDeleteCompleted($event, $eventId, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   payload: array<string, mixed>,
     *   schedule: array{
     *     touched: bool,
     *     occurrences: array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>,
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
            'content',
            'thumb',
            'tags',
            'categories',
            'taxonomy_terms',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalized[$field] = $payload[$field];
            }
        }

        if (array_key_exists('type', $payload)) {
            $normalized['type'] = $this->resolveEventTypePayload($payload['type']);
        }

        if (array_key_exists('taxonomy_terms', $payload)) {
            $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForEvent($taxonomyTerms);
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

        return [
            'payload' => $normalized,
            'schedule' => $schedule,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   touched: bool,
     *   occurrences: array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>,
     *   date_time_start: Carbon|null,
     *   date_time_end: Carbon|null
     * }
     */
    private function resolveSchedulePayload(array $payload, ?Event $existing): array
    {
        $hasOccurrences = array_key_exists('occurrences', $payload);

        if ($hasOccurrences) {
            $occurrences = $this->normalizeOccurrences($payload['occurrences']);

            return $this->buildScheduleResult(true, $occurrences);
        }

        if ($existing === null) {
            throw ValidationException::withMessages([
                'occurrences' => ['occurrences is required.'],
            ]);
        }

        $existingOccurrences = $this->extractExistingOccurrences($existing);
        $firstOccurrence = $existingOccurrences[0] ?? null;

        return [
            'touched' => false,
            'occurrences' => $existingOccurrences,
            'date_time_start' => $firstOccurrence['date_time_start'] ?? null,
            'date_time_end' => $firstOccurrence['date_time_end'] ?? null,
        ];
    }

    /**
     * @return array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>
     */
    private function normalizeOccurrences(mixed $occurrences): array
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

            $normalized[] = [
                'date_time_start' => $start,
                'date_time_end' => $end,
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return $left['date_time_start']->getTimestamp() <=> $right['date_time_start']->getTimestamp();
        });

        return $normalized;
    }

    /**
     * @param  array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>  $occurrences
     * @return array{
     *   touched: bool,
     *   occurrences: array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>,
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

    /**
     * @return array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}>
     */
    private function extractExistingOccurrences(?Event $existing): array
    {
        if (! $existing) {
            return [];
        }

        $eventId = (string) $existing->_id;
        $fromCollection = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->orderBy('starts_at')
            ->get();

        if ($fromCollection->isEmpty()) {
            throw new RuntimeException(
                'Event occurrences are required for updates without schedule mutation. '.
                'Provide occurrences payload to rebuild the schedule.'
            );
        }

        $occurrences = [];
        foreach ($fromCollection as $occurrence) {
            $start = $this->tryNormalizeDateValue($occurrence->starts_at ?? null);
            if (! $start) {
                continue;
            }

            $end = $this->tryNormalizeDateValue($occurrence->ends_at ?? null);
            if ($end && $end->lessThan($start)) {
                continue;
            }

            $occurrences[] = [
                'date_time_start' => $start,
                'date_time_end' => $end,
            ];
        }

        if ($occurrences === []) {
            throw new RuntimeException(
                'Event occurrences collection has no valid schedule entries for this event.'
            );
        }

        return $occurrences;
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

    private function tryNormalizeDateValue(mixed $value): ?Carbon
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
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        return $value;
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

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function runTenantTransaction(callable $callback): mixed
    {
        $connection = DB::connection('tenant');

        if (! method_exists($connection, 'transaction')) {
            throw new RuntimeException(
                'Tenant MongoDB transaction support is required for events writes, but the active driver has no transaction API.'
            );
        }

        try {
            return $connection->transaction(static fn () => $callback());
        } catch (\Throwable $throwable) {
            if ($this->isTransactionSupportError($throwable)) {
                throw new RuntimeException(
                    'Tenant MongoDB transaction support is required for events writes. Configure replica set / transaction-capable runtime.',
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }
    }

    private function isTransactionSupportError(\Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'transaction numbers are only allowed')
            || str_contains($message, 'transactions are not supported')
            || str_contains($message, 'replica set')
            || str_contains($message, 'mongos')
            || str_contains($message, 'starttransaction');
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
