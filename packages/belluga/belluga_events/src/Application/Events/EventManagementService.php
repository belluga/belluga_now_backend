<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

class EventManagementService
{
    public function __construct(
        private readonly EventTaxonomyValidationContract $taxonomyValidationService,
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventCapabilitiesService $eventCapabilities,
        private readonly EventOccurrenceSyncService $eventOccurrenceSyncService,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): Event
    {
        $normalized = $this->normalizePayloadAndSchedule($payload, null);

        /** @var Event $event */
        $event = $this->runTenantTransaction(function () use ($normalized): Event {
            $created = Event::query()->create($normalized['payload']);
            $this->eventOccurrenceSyncService->syncFromEvent($created, $normalized['schedule']['occurrences']);

            return $created->fresh();
        });

        $this->events->dispatch(new EventCreated((string) $event->_id));

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(Event $event, array $payload): Event
    {
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

        return $updated;
    }

    public function delete(Event $event): void
    {
        $eventId = (string) $event->_id;

        $this->runTenantTransaction(function () use ($event, $eventId): null {
            $event->delete();
            $this->eventOccurrenceSyncService->softDeleteByEventId($eventId);

            return null;
        });

        $this->events->dispatch(new EventDeleted($eventId));
    }

    /**
     * @param array<string, mixed> $payload
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
            'type',
            'thumb',
            'tags',
            'categories',
            'taxonomy_terms',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalized[$field] = $payload[$field];
            }
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

        $venueId = $payload['venue_id'] ?? null;
        $artistIds = $payload['artist_ids'] ?? null;

        if ($venueId !== null || $existing === null) {
            $resolvedVenue = $this->resolveVenuePayload($venueId, $existing);
            $normalized['venue'] = $resolvedVenue['venue'];
            $normalized['geo_location'] = $resolvedVenue['location'];
            $normalized['account_id'] = $resolvedVenue['account_id'];
            $normalized['account_profile_id'] = $resolvedVenue['account_profile_id'];

            $expectedAccountId = $payload['account_id'] ?? null;
            if ($expectedAccountId && $expectedAccountId !== $resolvedVenue['account_id']) {
                throw ValidationException::withMessages([
                    'account_id' => ['Account must match the venue account.'],
                ]);
            }

            $expectedProfileId = $payload['account_profile_id'] ?? null;
            if ($expectedProfileId && $expectedProfileId !== $resolvedVenue['account_profile_id']) {
                throw ValidationException::withMessages([
                    'account_profile_id' => ['Account profile must match the venue profile.'],
                ]);
            }
        }

        if ($artistIds !== null || $existing === null) {
            $normalized['artists'] = $this->resolveArtistPayloads($artistIds, $existing);
        }

        return [
            'payload' => $normalized,
            'schedule' => $schedule,
        ];
    }

    /**
     * @param array<string, mixed> $payload
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
     * @param mixed $occurrences
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
     * @param array<int, array{date_time_start: Carbon, date_time_end: Carbon|null}> $occurrences
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
                'Event occurrences are required for updates without schedule mutation. ' .
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
            } catch (\Throwable) {
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
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $publication
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
     * @return array{venue: array<string, mixed>, location: array<string, mixed>, account_id: string, account_profile_id: string}
     */
    private function resolveVenuePayload(?string $venueId, ?Event $existing): array
    {
        $id = $venueId ?? ($existing?->venue['id'] ?? null);

        if (! $id) {
            throw ValidationException::withMessages([
                'venue_id' => ['Venue account profile is required.'],
            ]);
        }

        return $this->eventProfileResolver->resolveVenueByProfileId((string) $id);
    }

    /**
     * @param array<int, string>|null $artistIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveArtistPayloads(?array $artistIds, ?Event $existing): array
    {
        $ids = $artistIds;
        if ($ids === null) {
            $current = $existing?->artists ?? [];
            $current = is_array($current) ? $current : [];

            return $current;
        }

        if ($ids === []) {
            return [];
        }

        return $this->eventProfileResolver->resolveArtistsByProfileIds(array_values($ids));
    }

    private function normalizePublishAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @template T
     * @param callable(): T $callback
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
}
