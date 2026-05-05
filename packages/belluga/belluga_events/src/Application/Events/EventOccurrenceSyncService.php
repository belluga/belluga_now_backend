<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class EventOccurrenceSyncService
{
    private const DEFAULT_EVENT_DURATION_HOURS = 3;

    public function __construct(
        private readonly EventTaxonomySnapshotResolverContract $taxonomySnapshotResolver,
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventAccountContextResolver $eventAccountContextResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function syncFromEvent(Event $event, array $occurrences): void
    {
        $eventId = (string) $event->_id;
        $now = Carbon::now();
        $publication = $this->normalizePublication($event->publication ?? [], $event->created_at);
        $eventGeoLocation = $this->resolveEventGeoLocation($event);
        $existingDocuments = $this->loadOrderedExistingDocuments($event);
        $documentsById = $this->keyDocumentsById($existingDocuments);
        $documentsBySlug = $this->keyDocumentsBySlug($existingDocuments);
        $resolvedDocuments = [];
        $allowIndexFallback = ! $this->occurrencesContainIdentity($occurrences);
        foreach ($occurrences as $index => $occurrence) {
            $resolvedDocuments[$index] = is_array($occurrence)
                ? $this->resolveExistingOccurrenceDocument(
                    $occurrence,
                    $existingDocuments,
                    $documentsById,
                    $documentsBySlug,
                    (int) $index,
                    $allowIndexFallback
                )
                : null;
        }
        $claimedOccurrenceSlugs = $this->collectClaimedOccurrenceSlugs($occurrences, $resolvedDocuments);

        $activeDocumentIds = [];
        $occurrenceRefs = [];
        foreach ($occurrences as $index => $occurrence) {
            $start = $this->toCarbon($occurrence['date_time_start'] ?? null) ?? $now;
            $end = $this->toCarbon($occurrence['date_time_end'] ?? null);
            $effectiveEnd = $this->resolveEffectiveEnd($start, $end);
            $eventTaxonomyTerms = $this->ensureTaxonomySnapshots($event->taxonomy_terms ?? []);
            $ownTaxonomyTerms = $this->ensureTaxonomySnapshots($occurrence['taxonomy_terms'] ?? []);
            $effectiveTaxonomyTerms = $ownTaxonomyTerms !== [] ? $ownTaxonomyTerms : $eventTaxonomyTerms;
            $eventParties = $this->normalizeEventParties($event->event_parties ?? []);
            $ownEventParties = $this->normalizeEventParties($occurrence['event_parties'] ?? []);
            $effectiveEventParties = $this->mergeEventParties($eventParties, $ownEventParties);
            $effectiveLocation = $this->resolveEffectiveLocationPayload($event, $occurrence, $eventGeoLocation);
            $programmingItems = $this->normalizeProgrammingItems($occurrence['programming_items'] ?? []);
            $document = $resolvedDocuments[$index] ?? null;

            $payload = [
                'event_id' => $eventId,
                'slug' => (string) ($event->slug ?? ''),
                'occurrence_slug' => $this->resolveOccurrenceSlug($occurrence, $document, (string) ($event->slug ?? ''), $eventId, $index, $claimedOccurrenceSlugs),
                'title' => (string) ($event->title ?? ''),
                'content' => (string) ($event->content ?? ''),
                'type' => $this->normalizeArray($event->type ?? []),
                'thumb' => $this->normalizeArray($event->thumb ?? null),
                'location' => $effectiveLocation['location'],
                'place_ref' => $effectiveLocation['place_ref'],
                'venue' => $effectiveLocation['venue'],
                'geo_location' => $effectiveLocation['geo_location'],
                'has_location_override' => $effectiveLocation['has_location_override'],
                'location_override' => $effectiveLocation['location_override'],
                'own_event_parties' => $ownEventParties,
                'own_linked_account_profiles' => $this->resolveLinkedAccountProfiles($ownEventParties),
                'linked_account_profiles' => $this->resolveLinkedAccountProfiles($effectiveEventParties),
                'artists' => $this->deriveArtistsReadProjection($effectiveEventParties),
                'tags' => $this->normalizeArray($event->tags ?? []),
                'categories' => $this->normalizeArray($event->categories ?? []),
                'own_taxonomy_terms' => $ownTaxonomyTerms,
                'taxonomy_terms' => $effectiveTaxonomyTerms,
                'capabilities' => $this->normalizeArray($event->capabilities ?? []),
                'created_by' => $this->normalizeArray($event->created_by ?? []),
                'event_parties' => $effectiveEventParties,
                'programming_items' => $programmingItems,
                'account_context_ids' => $this->eventAccountContextResolver->resolveForOccurrence(
                    $this->normalizeArray($event->account_context_ids ?? []),
                    $effectiveEventParties,
                    $effectiveLocation['place_ref'],
                    $programmingItems
                ),
                'publication' => $publication,
                'is_event_published' => $this->isEffectivelyPublished($publication, $now),
                'is_active' => (bool) ($event->is_active ?? true),
                'starts_at' => $start,
                'ends_at' => $end,
                'effective_ends_at' => $effectiveEnd,
                'updated_from_event_at' => $now,
                'deleted_at' => null,
            ];

            if ($document) {
                $document->unset('occurrence_index');
                $document->fill($payload);
                $document->save();
            } else {
                $document = EventOccurrence::query()->create($payload);
            }

            if (isset($document->_id)) {
                $documentId = (string) $document->_id;
                $activeDocumentIds[] = $documentId;
                $occurrenceRefs[] = [
                    'occurrence_id' => $documentId,
                    'occurrence_slug' => $this->normalizeOptionalString($document->occurrence_slug ?? null),
                    'order' => $index,
                ];
            }
        }

        $event->forceFill([
            'occurrence_refs' => $occurrenceRefs,
        ])->saveQuietly();

        $staleDocuments = EventOccurrence::withTrashed()
            ->where('event_id', $eventId);
        if ($activeDocumentIds !== []) {
            $staleDocuments->whereNotIn('_id', $this->buildDocumentIdCandidates($activeDocumentIds));
        }

        foreach ($staleDocuments->cursor() as $document) {
            $document->unset('occurrence_index');
            $document->deleted_at = $now;
            $document->updated_at = $now;
            $document->save();
        }
    }

    /**
     * @param  array<string, mixed>  $occurrence
     */
    private function resolveExistingOccurrenceDocument(
        array $occurrence,
        array $orderedDocuments,
        array $documentsById,
        array $documentsBySlug,
        int $index,
        bool $allowIndexFallback
    ): ?EventOccurrence {
        foreach (['occurrence_id', 'id'] as $field) {
            $id = $this->normalizeOptionalString($occurrence[$field] ?? null);
            if ($id === null) {
                continue;
            }

            $document = $documentsById[$id] ?? null;
            if ($document) {
                return $document;
            }
        }

        $slug = $this->normalizeOptionalString($occurrence['occurrence_slug'] ?? null);
        if ($slug !== null) {
            $document = $documentsBySlug[$slug] ?? null;
            if ($document) {
                return $document;
            }
        }

        if (! $allowIndexFallback) {
            return null;
        }

        return $orderedDocuments[$index] ?? null;
    }

    /**
     * @return array<int, EventOccurrence>
     */
    private function loadOrderedExistingDocuments(Event $event): array
    {
        $eventId = isset($event->_id) ? (string) $event->_id : '';
        if ($eventId === '') {
            return [];
        }

        $documents = EventOccurrence::withTrashed()
            ->where('event_id', $eventId)
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->get()
            ->values()
            ->all();

        $occurrenceRefs = $this->normalizeOccurrenceRefs($event->occurrence_refs ?? []);
        if ($occurrenceRefs === []) {
            return $documents;
        }

        $orderById = [];
        $orderBySlug = [];
        foreach ($occurrenceRefs as $ref) {
            $order = (int) ($ref['order'] ?? count($orderById));
            $occurrenceId = $this->normalizeOptionalString($ref['occurrence_id'] ?? null);
            $occurrenceSlug = $this->normalizeOptionalString($ref['occurrence_slug'] ?? null);
            if ($occurrenceId !== null) {
                $orderById[$occurrenceId] = $order;
            }
            if ($occurrenceSlug !== null) {
                $orderBySlug[$occurrenceSlug] = $order;
            }
        }

        usort($documents, function (EventOccurrence $left, EventOccurrence $right) use ($orderById, $orderBySlug): int {
            $leftRank = $this->resolveDocumentOrderRank($left, $orderById, $orderBySlug);
            $rightRank = $this->resolveDocumentOrderRank($right, $orderById, $orderBySlug);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            $leftStart = $this->toCarbon($left->starts_at)?->getTimestamp() ?? PHP_INT_MAX;
            $rightStart = $this->toCarbon($right->starts_at)?->getTimestamp() ?? PHP_INT_MAX;
            if ($leftStart !== $rightStart) {
                return $leftStart <=> $rightStart;
            }

            return strcmp((string) ($left->_id ?? ''), (string) ($right->_id ?? ''));
        });

        return $documents;
    }

    /**
     * @param  array<int, EventOccurrence>  $documents
     * @return array<string, EventOccurrence>
     */
    private function keyDocumentsById(array $documents): array
    {
        $mapped = [];
        foreach ($documents as $document) {
            $documentId = isset($document->_id) ? trim((string) $document->_id) : '';
            if ($documentId !== '') {
                $mapped[$documentId] = $document;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<int, EventOccurrence>  $documents
     * @return array<string, EventOccurrence>
     */
    private function keyDocumentsBySlug(array $documents): array
    {
        $mapped = [];
        foreach ($documents as $document) {
            $slug = $this->normalizeOptionalString($document->occurrence_slug ?? null);
            if ($slug !== null) {
                $mapped[$slug] = $document;
            }
        }

        return $mapped;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOccurrenceRefs(mixed $value): array
    {
        $refs = $this->normalizeArray($value);

        return array_values(array_filter($refs, static fn (mixed $ref): bool => is_array($ref)));
    }

    /**
     * @param  array<string, int>  $orderById
     * @param  array<string, int>  $orderBySlug
     */
    private function resolveDocumentOrderRank(EventOccurrence $document, array $orderById, array $orderBySlug): int
    {
        $documentId = isset($document->_id) ? trim((string) $document->_id) : '';
        if ($documentId !== '' && array_key_exists($documentId, $orderById)) {
            return $orderById[$documentId];
        }

        $slug = $this->normalizeOptionalString($document->occurrence_slug ?? null);
        if ($slug !== null && array_key_exists($slug, $orderBySlug)) {
            return $orderBySlug[$slug];
        }

        return PHP_INT_MAX;
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    private function occurrencesContainIdentity(array $occurrences): bool
    {
        foreach ($occurrences as $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }

            foreach (['occurrence_id', 'id', 'occurrence_slug'] as $field) {
                if ($this->normalizeOptionalString($occurrence[$field] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     * @param  array<int, EventOccurrence|null>  $documents
     * @return array<string, bool>
     */
    private function collectClaimedOccurrenceSlugs(array $occurrences, array $documents): array
    {
        $claimed = [];
        foreach ($occurrences as $index => $occurrence) {
            if (! is_array($occurrence)) {
                continue;
            }

            $payloadSlug = $this->normalizeOptionalString($occurrence['occurrence_slug'] ?? null);
            if ($payloadSlug !== null) {
                $claimed[$payloadSlug] = true;
            }

            $document = $documents[$index] ?? null;
            $existingSlug = $this->normalizeOptionalString($document?->occurrence_slug ?? null);
            if ($existingSlug !== null) {
                $claimed[$existingSlug] = true;
            }
        }

        return $claimed;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     */
    private function resolveOccurrenceSlug(
        array $occurrence,
        ?EventOccurrence $document,
        string $eventSlug,
        string $eventId,
        int $index,
        array &$claimedOccurrenceSlugs
    ): string {
        $payloadSlug = $this->normalizeOptionalString($occurrence['occurrence_slug'] ?? null);
        if ($payloadSlug !== null) {
            $claimedOccurrenceSlugs[$payloadSlug] = true;

            return $payloadSlug;
        }

        $existingSlug = $this->normalizeOptionalString($document?->occurrence_slug ?? null);
        if ($existingSlug !== null) {
            $claimedOccurrenceSlugs[$existingSlug] = true;

            return $existingSlug;
        }

        return $this->buildUniqueOccurrenceSlug($eventSlug, $eventId, $index, $claimedOccurrenceSlugs);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int, string>  $documentIds
     * @return array<int, string|ObjectId>
     */
    private function buildDocumentIdCandidates(array $documentIds): array
    {
        $candidates = [];
        foreach ($documentIds as $documentId) {
            $normalized = trim($documentId);
            if ($normalized === '') {
                continue;
            }
            $candidates[] = $normalized;
            if ($this->looksLikeObjectId($normalized)) {
                $candidates[] = new ObjectId($normalized);
            }
        }

        return $candidates;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }

    /**
     * @param  array<string, mixed>  $publication
     */
    public function mirrorPublicationByEventId(string $eventId, array $publication, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return EventOccurrence::query()->where('event_id', $eventId)->update([
            'publication' => $publication,
            'is_event_published' => $this->isEffectivelyPublished($publication, $now),
            'updated_from_event_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function softDeleteByEventId(string $eventId, mixed $deletedAt = null): void
    {
        $now = $this->toCarbon($deletedAt) ?? Carbon::now();

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
     * @param  array<string, mixed>  $occurrence
     * @param  array<string, mixed>  $eventGeoLocation
     * @return array{
     *   location: array<string, mixed>,
     *   place_ref: array<string, mixed>|null,
     *   venue: array<string, mixed>,
     *   geo_location: array<string, mixed>|null,
     *   has_location_override: bool,
     *   location_override: array<string, mixed>|null
     * }
     */
    private function resolveEffectiveLocationPayload(Event $event, array $occurrence, array $eventGeoLocation): array
    {
        return [
            'location' => $this->normalizeArray($event->location ?? []),
            'place_ref' => $this->normalizeNullableArray($event->place_ref ?? null),
            'venue' => $this->normalizeArray($event->venue ?? null),
            'geo_location' => $eventGeoLocation === [] ? null : $eventGeoLocation,
            'has_location_override' => false,
            'location_override' => null,
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
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function deriveArtistsReadProjection(array $eventParties): array
    {
        return array_map(
            static function (array $profile): array {
                $profile['highlight'] = false;

                return $profile;
            },
            $this->resolveLinkedAccountProfiles($eventParties)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function resolveLinkedAccountProfiles(array $eventParties): array
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
                'genres' => array_values($this->normalizeArray($metadata['genres'] ?? [])),
                'taxonomy_terms' => $this->ensureTaxonomySnapshots($metadata['taxonomy_terms'] ?? []),
            ];
        }

        return $profiles;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @param  array<int, array<string, mixed>>  $ownEventParties
     * @return array<int, array<string, mixed>>
     */
    private function mergeEventParties(array $eventParties, array $ownEventParties): array
    {
        $merged = [];
        $seen = [];

        foreach ([$eventParties, $ownEventParties] as $rows) {
            foreach ($rows as $row) {
                $party = $this->normalizeArray($row);
                $partyType = trim((string) ($party['party_type'] ?? ''));
                $partyRefId = trim((string) ($party['party_ref_id'] ?? ''));
                if ($partyType === '' || $partyRefId === '') {
                    continue;
                }

                $key = "{$partyType}:{$partyRefId}";
                if (isset($seen[$key])) {
                    continue;
                }

                $merged[] = $party;
                $seen[$key] = true;
            }
        }

        return $merged;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProgrammingItems(mixed $items): array
    {
        $rows = $this->normalizeArray($items);
        if ($rows === []) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            $item = $this->normalizeArray($row);
            if ($item === []) {
                continue;
            }

            $linkedProfiles = [];
            foreach ($this->normalizeArray($item['linked_account_profiles'] ?? []) as $profile) {
                $profilePayload = $this->normalizeArray($profile);
                if ($profilePayload === []) {
                    continue;
                }
                if (array_key_exists('taxonomy_terms', $profilePayload)) {
                    $profilePayload['taxonomy_terms'] = $this->ensureTaxonomySnapshots($profilePayload['taxonomy_terms']);
                }
                $linkedProfiles[] = $profilePayload;
            }

            $normalized[] = [
                'time' => (string) ($item['time'] ?? ''),
                'end_time' => isset($item['end_time']) && $item['end_time'] !== null
                    ? (string) $item['end_time']
                    : null,
                'title' => $item['title'] ?? null,
                'account_profile_ids' => array_values($this->normalizeArray($item['account_profile_ids'] ?? [])),
                'linked_account_profiles' => $linkedProfiles,
                'place_ref' => $this->normalizeNullableArray($item['place_ref'] ?? null),
                'location_profile' => $this->normalizeNullableArray($item['location_profile'] ?? null),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEventParties(mixed $eventParties): array
    {
        $rows = $this->normalizeArray($eventParties);
        $normalized = [];

        foreach ($rows as $row) {
            $party = $this->normalizeArray($row);
            if ($party === []) {
                continue;
            }

            $metadata = $this->normalizeArray($party['metadata'] ?? []);
            if ($metadata !== [] && array_key_exists('taxonomy_terms', $metadata)) {
                $metadata['taxonomy_terms'] = $this->ensureTaxonomySnapshots($metadata['taxonomy_terms']);
                $party['metadata'] = $metadata;
            }

            $normalized[] = $party;
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function ensureTaxonomySnapshots(mixed $terms): array
    {
        $items = $this->normalizeArray($terms);
        if ($items === []) {
            return [];
        }

        return $this->taxonomySnapshotResolver->ensureSnapshots($items);
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

    /**
     * @param  array<string, bool>  $claimedOccurrenceSlugs
     */
    private function buildUniqueOccurrenceSlug(
        string $eventSlug,
        string $eventId,
        int $index,
        array &$claimedOccurrenceSlugs
    ): string {
        $candidateIndex = $index;

        do {
            $candidate = $this->buildOccurrenceSlug($eventSlug, $eventId, $candidateIndex);
            $candidateIndex++;
        } while (
            isset($claimedOccurrenceSlugs[$candidate])
            || EventOccurrence::withTrashed()->where('occurrence_slug', $candidate)->exists()
        );

        $claimedOccurrenceSlugs[$candidate] = true;

        return $candidate;
    }
}
