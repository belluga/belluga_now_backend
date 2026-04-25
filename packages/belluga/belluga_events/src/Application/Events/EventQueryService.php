<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventAttendanceReadContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Eloquent\Collection;

class EventQueryService
{
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_MANAGEMENT_PAGE_SIZE = 100;

    private const DEFAULT_EVENT_DURATION_MS = 10800000; // 3h

    /** @var array<string, mixed>|null */
    private ?array $tenantCapabilitiesCache = null;

    private readonly EventManagementOccurrenceQuery $managementOccurrenceQuery;

    public function __construct(
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventRadiusSettingsContract $eventRadiusSettings,
        private readonly EventCapabilitySettingsContract $eventCapabilitySettings,
        private readonly EventAttendanceReadContract $eventAttendanceRead,
        private readonly EventTaxonomySnapshotResolverContract $taxonomySnapshotResolver,
        ?EventManagementOccurrenceQuery $managementOccurrenceQuery = null,
    ) {
        $this->managementOccurrenceQuery = $managementOccurrenceQuery
            ?? new EventManagementOccurrenceQuery();
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchAgenda(array $queryParams, ?string $userId): array
    {
        $page = $this->normalizePublicPage($queryParams['page'] ?? 1);
        $pageSize = (int) ($queryParams['page_size'] ?? $queryParams['per_page'] ?? self::DEFAULT_PAGE_SIZE);
        $pageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
        $pageSize = min($pageSize, InputConstraints::PUBLIC_PAGE_SIZE_MAX);
        $skip = ($page - 1) * $pageSize;
        $limit = $pageSize + 1;

        $filters = $this->normalizeFilters($queryParams);
        $useGeo = $filters['use_geo'] && ! $filters['confirmed_only'];
        $raw = $this->runAgendaQuery($filters, $userId, $skip, $limit, $useGeo);

        $hasMore = count($raw) > $pageSize;
        $pageSlice = array_slice($raw, 0, $pageSize);

        return [
            'items' => array_map(fn ($event) => $this->formatEvent($event, $userId), $pageSlice),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    public function paginateManagement(
        array $queryParams,
        bool $includeArchived,
        int $perPage,
        bool $isAdminContext,
        ?string $accountContextId = null
    ): LengthAwarePaginator {
        $resolvedPerPage = max(1, min($perPage, self::MAX_MANAGEMENT_PAGE_SIZE));
        $resolvedPage = max(1, (int) ($queryParams['page'] ?? 1));
        if (! $isAdminContext) {
            $resolvedPage = $this->normalizePublicPage($resolvedPage);
            $queryParams['page'] = $resolvedPage;
        }
        $temporalBuckets = $this->extractManagementTemporalBuckets($queryParams);
        $specificDate = $this->extractManagementSpecificDate($queryParams);

        if (! $includeArchived && ($temporalBuckets !== [] || $specificDate !== null)) {
            return $this->paginateManagementFromOccurrences(
                $queryParams,
                $temporalBuckets,
                $specificDate,
                $resolvedPerPage,
                $isAdminContext,
                $accountContextId
            );
        }

        $query = Event::query();

        if ($includeArchived && $isAdminContext) {
            $query->onlyTrashed();
        }

        if ($accountContextId) {
            $this->applyAccountFiltersToQuery($query, $accountContextId);
        }

        if (array_key_exists('status', $queryParams) && $queryParams['status'] !== null) {
            $query->where('publication.status', $queryParams['status']);
        }

        if ($temporalBuckets !== []) {
            $this->applyManagementTemporalFilter($query, $temporalBuckets);
        }

        if ($specificDate !== null) {
            $this->applyManagementSpecificDateFilter($query, $specificDate);
        }

        $venueProfileId = $this->extractManagementProfileFilterId($queryParams, 'venue_profile_id');
        if ($venueProfileId !== null) {
            $this->applyManagementVenueFilter($query, $venueProfileId);
        }

        $relatedAccountProfileId = $this->extractManagementProfileFilterId($queryParams, 'related_account_profile_id');
        if ($relatedAccountProfileId !== null) {
            $this->applyManagementRelatedAccountProfileFilter($query, $relatedAccountProfileId);
        }

        if (! $isAdminContext) {
            $this->applyPublicPublicationFilter($query);
        }

        $paginator = $query
            ->orderBy('date_time_start', $isAdminContext ? 'asc' : 'desc')
            ->orderBy('_id', 'desc')
            ->paginate($resolvedPerPage, ['*'], 'page', $resolvedPage);

        $events = $paginator->getCollection();
        $occurrencesByEventId = $this->loadOccurrencesByEventIds(
            $events
                ->map(static fn (Event $event): string => isset($event->_id) ? (string) $event->_id : '')
                ->filter()
                ->values()
                ->all()
        );

        $paginator->setCollection(
            $events->map(fn (Event $event): array => $this->formatManagementEvent($event, $occurrencesByEventId))
        );

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<int, string>
     */
    private function extractManagementTemporalBuckets(array $queryParams): array
    {
        $raw = Arr::get($queryParams, 'temporal', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        $allowed = ['past', 'now', 'future'];
        $normalized = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '' || ! in_array($trimmed, $allowed, true)) {
                continue;
            }
            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, string>  $temporalBuckets
     */
    private function applyManagementTemporalFilter(mixed $query, array $temporalBuckets): void
    {
        $now = new UTCDateTime(Carbon::now());
        $effectiveEndExpr = [
            '$ifNull' => [
                '$date_time_end',
                [
                    '$add' => ['$date_time_start', self::DEFAULT_EVENT_DURATION_MS],
                ],
            ],
        ];

        $clauses = [];
        if (in_array('past', $temporalBuckets, true)) {
            $clauses[] = ['$lte' => [$effectiveEndExpr, $now]];
        }
        if (in_array('now', $temporalBuckets, true)) {
            $clauses[] = [
                '$and' => [
                    ['$lte' => ['$date_time_start', $now]],
                    ['$gt' => [$effectiveEndExpr, $now]],
                ],
            ];
        }
        if (in_array('future', $temporalBuckets, true)) {
            $clauses[] = ['$gt' => ['$date_time_start', $now]];
        }

        if ($clauses === []) {
            return;
        }

        $query->whereRaw([
            '$expr' => count($clauses) === 1
                ? $clauses[0]
                : ['$or' => $clauses],
        ]);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function extractManagementProfileFilterId(array $queryParams, string $key): ?string
    {
        $raw = Arr::get($queryParams, $key);
        if (! is_string($raw)) {
            return null;
        }

        $normalized = trim($raw);

        return $normalized === '' ? null : $normalized;
    }

    private function extractManagementSpecificDate(array $queryParams): ?Carbon
    {
        $raw = Arr::get($queryParams, 'date');
        if (! is_string($raw)) {
            return null;
        }

        $normalized = trim($raw);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $normalized)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyManagementSpecificDateFilter(mixed $query, Carbon $specificDate): void
    {
        $dayStart = new UTCDateTime($specificDate->copy()->startOfDay());
        $nextDayStart = new UTCDateTime($specificDate->copy()->addDay()->startOfDay());

        $query->where('date_time_start', '>=', $dayStart)
            ->where('date_time_start', '<', $nextDayStart);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<int, string>  $temporalBuckets
     */
    private function paginateManagementFromOccurrences(
        array $queryParams,
        array $temporalBuckets,
        ?Carbon $specificDate,
        int $perPage,
        bool $isAdminContext,
        ?string $accountContextId
    ): LengthAwarePaginator {
        $pageResult = $this->managementOccurrenceQuery->paginateEventIds(
            $queryParams,
            $temporalBuckets,
            $specificDate,
            $perPage,
            $isAdminContext,
            $accountContextId
        );

        $eventIds = $pageResult['event_ids'];
        $page = $pageResult['page'];
        $total = $pageResult['total'];
        if ($eventIds === []) {
            return $this->emptyManagementPaginator($perPage, $page, $total);
        }

        $eventsById = Event::query()
            ->whereIn('_id', $this->buildEventIdCandidates($eventIds))
            ->get()
            ->keyBy(static fn (Event $event): string => isset($event->_id) ? (string) $event->_id : '');

        $occurrencesByEventId = $this->loadOccurrencesByEventIds($eventIds);
        $items = collect($eventIds)
            ->map(fn (string $eventId): ?array => $eventsById->has($eventId)
                ? $this->formatManagementEvent($eventsById->get($eventId), $occurrencesByEventId)
                : null)
            ->filter()
            ->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    private function emptyManagementPaginator(int $perPage, int $page, int $total = 0): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            collect(),
            $total,
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    private function applyManagementVenueFilter(mixed $query, string $venueProfileId): void
    {
        $profileIds = $this->buildProfileIdCandidates($venueProfileId);

        $query->where(function ($builder) use ($profileIds): void {
            $builder->whereIn('place_ref.id', $profileIds)
                ->orWhereIn('place_ref._id', $profileIds);
        });
    }

    private function applyManagementRelatedAccountProfileFilter(mixed $query, string $relatedAccountProfileId): void
    {
        $profileIds = $this->buildProfileIdCandidates($relatedAccountProfileId);

        $query->whereRaw([
            'event_parties' => [
                '$elemMatch' => [
                    'party_type' => ['$ne' => 'venue'],
                    'party_ref_id' => ['$in' => $profileIds],
                ],
            ],
        ]);
    }

    public function findByIdOrSlug(string $eventId): ?Event
    {
        if ($this->looksLikeObjectId($eventId)) {
            $byId = Event::query()->where('_id', new ObjectId($eventId))->first();
            if (! $byId) {
                $byId = Event::query()->where('_id', $eventId)->first();
            }
            if ($byId) {
                return $byId;
            }
        }

        $bySlug = Event::query()->where('slug', $eventId)->first();
        if ($bySlug) {
            return $bySlug;
        }

        $occurrence = EventOccurrence::query()
            ->where('occurrence_slug', $eventId)
            ->first();
        if (! $occurrence) {
            return null;
        }

        $parentEventId = isset($occurrence->event_id) ? (string) $occurrence->event_id : '';
        if ($parentEventId === '') {
            return null;
        }

        if ($this->looksLikeObjectId($parentEventId)) {
            $parent = Event::query()->where('_id', new ObjectId($parentEventId))->first();
            if ($parent) {
                return $parent;
            }
        }

        return Event::query()->where('_id', $parentEventId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEventDetail(Event $event, ?string $userId = null, ?string $occurrenceRef = null): array
    {
        $preloadedOccurrences = $this->loadEventOccurrenceDocuments($event);
        $selectedOccurrence = $this->resolveSelectedOccurrence($event, $occurrenceRef, $preloadedOccurrences);
        if (! $selectedOccurrence) {
            return $this->formatEvent($event, $userId);
        }

        $selectedOccurrenceId = (string) $selectedOccurrence->_id;
        $payload = $this->formatEvent($selectedOccurrence, $userId);
        $payload['event_id'] = (string) $event->_id;
        $payload['slug'] = $this->scalarString($event->slug ?? null) ?? $payload['slug'];
        $payload['occurrences'] = $this->resolveEventOccurrences($event, $selectedOccurrenceId, $preloadedOccurrences);
        $payload['linked_account_profiles'] = $this->resolveDetailLinkedAccountProfiles(
            $this->resolveLinkedAccountProfiles(
                $this->normalizeEventParties($event->event_parties ?? [])
            ),
            $payload['occurrences']
        );
        if (array_key_exists('artists', $payload)) {
            $payload['artists'] = $this->resolveArtistsReadProjectionFromLinkedProfiles(
                $payload['linked_account_profiles']
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, iterable<int, EventOccurrence>>|null  $occurrencesByEventId
     * @return array<string, mixed>
     */
    public function formatManagementEvent(Event $event, ?array $occurrencesByEventId = null): array
    {
        $eventId = isset($event->_id) ? (string) $event->_id : '';
        $preloadedOccurrences = $eventId !== '' && $occurrencesByEventId !== null
            ? ($occurrencesByEventId[$eventId] ?? [])
            : null;
        $type = $this->normalizeArray($event->type ?? null);
        $location = $this->normalizeArray($event->location ?? []);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray($event->thumb ?? null)
        );
        $eventParties = $this->normalizeEventParties($event->event_parties ?? []);
        $effectiveEventParties = $this->mergeEventParties(
            $eventParties,
            $this->resolveOccurrenceOwnedEventParties($event, $preloadedOccurrences)
        );
        $linkedAccountProfiles = $this->resolveLinkedAccountProfiles($effectiveEventParties);
        $taxonomyTerms = $this->ensureTaxonomySnapshots($event->taxonomy_terms ?? []);
        $typeVisual = $this->normalizeEventTypeVisual(
            $this->normalizeArray($type['visual'] ?? $type['poi_visual'] ?? null)
        );
        $publication = $event->publication ?? null;
        $publication = is_array($publication) ? $publication : (array) $publication;
        $venueDisplay = $this->scalarString($venue['display_name'] ?? null)
            ?? $this->scalarString($venue['name'] ?? null);
        $venuePayload = $venue === [] ? null : [
            'id' => $this->resolveLegacyDocumentId($venue),
            'display_name' => $venueDisplay ?? '',
            'slug' => $this->scalarString($venue['slug'] ?? null),
            'profile_type' => $this->scalarString($venue['profile_type'] ?? null),
            'tagline' => $this->scalarString($venue['tagline'] ?? null),
            'hero_image_url' => $this->absoluteUrlString($venue['hero_image_url'] ?? null),
            'logo_url' => $this->absoluteUrlString($venue['logo_url'] ?? null),
            'avatar_url' => $this->absoluteUrlString($venue['avatar_url'] ?? null)
                ?? $this->absoluteUrlString($venue['logo_url'] ?? null),
            'cover_url' => $this->absoluteUrlString($venue['cover_url'] ?? null)
                ?? $this->absoluteUrlString($venue['hero_image_url'] ?? null),
            'taxonomy_terms' => $this->ensureTaxonomySnapshots($venue['taxonomy_terms'] ?? []),
        ];
        $geo = $this->normalizeArray($location['geo'] ?? $event->geo_location ?? null);
        $coordinates = $geo['coordinates'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];
        }

        $resolvedOccurrences = $this->resolveEventOccurrences($event, null, $preloadedOccurrences);
        $dateTimeStart = $this->formatDate($this->extractRawAttribute($event, 'date_time_start'));
        $dateTimeEnd = $this->formatDate($this->extractRawAttribute($event, 'date_time_end'));
        if (count($resolvedOccurrences) > 0) {
            $occurrences = $resolvedOccurrences;
            $dateTimeStart ??= $resolvedOccurrences[0]['date_time_start'] ?? null;
            $dateTimeEnd ??= $resolvedOccurrences[0]['date_time_end'] ?? null;
        } elseif ($dateTimeStart !== null) {
            $occurrences = [[
                'occurrence_id' => null,
                'occurrence_slug' => null,
                'date_time_start' => $dateTimeStart,
                'date_time_end' => $dateTimeEnd,
            ]];
        } else {
            $occurrences = $resolvedOccurrences;
            $dateTimeStart = $resolvedOccurrences[0]['date_time_start'] ?? null;
            $dateTimeEnd = $resolvedOccurrences[0]['date_time_end'] ?? null;
        }
        $createdBy = $this->normalizeArray($event->created_by ?? []);

        return [
            'event_id' => isset($event->_id) ? (string) $event->_id : '',
            'occurrence_id' => null,
            'slug' => $this->scalarString($event->slug ?? null) ?? '',
            'type' => [
                'id' => $this->resolveLegacyDocumentId($type),
                'name' => $this->scalarString($type['name'] ?? null) ?? '',
                'slug' => $this->scalarString($type['slug'] ?? null) ?? '',
                'description' => $this->scalarString($type['description'] ?? null),
                'visual' => $typeVisual,
                'poi_visual' => $typeVisual,
                'icon' => $this->scalarString($type['icon'] ?? null),
                'color' => $this->scalarString($type['color'] ?? null),
                'icon_color' => $this->scalarString($type['icon_color'] ?? null),
            ],
            'title' => $this->scalarString($event->title ?? null) ?? '',
            'content' => $this->scalarString($event->content ?? null) ?? '',
            'location' => $location === [] ? null : $location,
            'place_ref' => $placeRef === [] ? null : $placeRef,
            'venue' => $venuePayload,
            'latitude' => $lat,
            'longitude' => $lng,
            'thumb' => $thumb,
            'date_time_start' => $dateTimeStart,
            'date_time_end' => $dateTimeEnd,
            'occurrences' => $occurrences,
            'created_by' => [
                'type' => $this->scalarString($createdBy['type'] ?? null) ?? '',
                'id' => $this->scalarString($createdBy['id'] ?? null) ?? '',
            ],
            'event_parties' => $eventParties,
            'linked_account_profiles' => $linkedAccountProfiles,
            'capabilities' => $this->resolveEventCapabilities($event),
            'taxonomy_terms' => $taxonomyTerms,
            'publication' => [
                'status' => $this->scalarString($publication['status'] ?? null) ?? 'draft',
                'publish_at' => $this->formatDate($publication['publish_at'] ?? null),
            ],
            'created_at' => $event->created_at?->toJSON(),
            'updated_at' => $event->updated_at?->toJSON(),
            'deleted_at' => $event->deleted_at?->toJSON(),
        ];
    }

    public function eventBelongsToAccount(Event $event, string $accountId): bool
    {
        $profileIds = $this->resolveAccountProfileIds($accountId);
        if ($profileIds === []) {
            return false;
        }

        if ($this->eventReferencesPlaceRefProfile($event, $profileIds)) {
            return true;
        }

        $parties = $this->normalizeEventParties($event->event_parties ?? []);
        foreach ($parties as $party) {
            if (in_array($party['party_ref_id'], $profileIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function eventEditableByAccount(Event $event, string $accountId, ?string $actorUserId = null): bool
    {
        $profileIds = $this->resolveAccountProfileIds($accountId);
        if ($profileIds === []) {
            return false;
        }

        $referencesAccountPlace = $this->eventReferencesPlaceRefProfile($event, $profileIds);
        $matchingParties = [];

        $parties = $this->normalizeEventParties($event->event_parties ?? []);
        foreach ($parties as $party) {
            if (in_array($party['party_ref_id'], $profileIds, true)) {
                $matchingParties[] = $party;
            }
        }

        if (! $referencesAccountPlace && $matchingParties === []) {
            return false;
        }

        if ($actorUserId !== null && $this->isAccountOwner($event, $actorUserId)) {
            return true;
        }

        if ($referencesAccountPlace) {
            return true;
        }

        foreach ($matchingParties as $party) {
            if ((bool) ($party['permissions']['can_edit'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function assertPublicVisible(Event $event): void
    {
        $publication = $event->publication ?? [];
        $publication = is_array($publication) ? $publication : (array) $publication;
        $status = (string) ($publication['status'] ?? 'published');
        $publishAt = $publication['publish_at'] ?? null;

        if ($status !== 'published') {
            throw new EventNotPubliclyVisibleException;
        }

        if ($publishAt instanceof UTCDateTime) {
            $publishAt = $publishAt->toDateTime();
        }

        if ($publishAt instanceof \DateTimeInterface && $publishAt > Carbon::now()) {
            throw new EventNotPubliclyVisibleException;
        }
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<int, array{event_id: string, occurrence_id: string, type: string, updated_at: string}>
     */
    public function buildStreamDeltas(array $queryParams, ?string $userId, ?string $lastEventId): array
    {
        $startedAt = microtime(true);
        $since = $this->parseSince($lastEventId);
        if (! $since) {
            Log::info('events_stream_deltas_skipped_invalid_cursor', [
                'last_event_id' => $lastEventId,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return [];
        }

        $filters = $this->normalizeFilters($queryParams);
        $useGeo = $filters['use_geo'] && ! $filters['confirmed_only'];
        $raw = $this->runStreamQuery($filters, $userId, $since, $useGeo);
        $deltas = array_values(array_filter(array_map(function ($event) use ($since): ?array {
            $payload = $this->formatStreamDelta($event, $since);

            return $payload['type'] ? $payload : null;
        }, $raw)));

        Log::info('events_stream_deltas_built', [
            'delta_count' => count($deltas),
            'duration_ms' => $this->durationMs($startedAt),
            'since' => $since->toISOString(),
            'use_geo' => (bool) ($filters['use_geo'] ?? false),
            'category_filter_count' => count($filters['categories'] ?? []),
            'tag_filter_count' => count($filters['tags'] ?? []),
            'taxonomy_filter_count' => count($filters['taxonomy'] ?? []),
            'confirmed_only' => (bool) ($filters['confirmed_only'] ?? false),
        ]);

        return $deltas;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $queryParams): array
    {
        $originLat = Arr::get($queryParams, 'origin_lat');
        $originLng = Arr::get($queryParams, 'origin_lng');

        $originLat = $this->normalizeLatitude($originLat);
        $originLng = $this->normalizeLongitude($originLng);

        $useGeo = $originLat !== null && $originLng !== null;

        return [
            'categories' => $this->normalizeStringArray($queryParams['categories'] ?? []),
            'tags' => $this->normalizeStringArray($queryParams['tags'] ?? []),
            'taxonomy' => $this->normalizeTaxonomyArray($queryParams['taxonomy'] ?? []),
            'search' => $this->extractSearchQuery($queryParams),
            'past_only' => filter_var($queryParams['past_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'live_now_only' => filter_var($queryParams['live_now_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'confirmed_only' => filter_var($queryParams['confirmed_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'max_distance_meters' => $useGeo ? $this->resolveMaxDistanceMeters($queryParams) : null,
            'use_geo' => $useGeo,
        ];
    }

    private function normalizeLatitude(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return $coordinate >= -90.0 && $coordinate <= 90.0 ? $coordinate : null;
    }

    private function normalizePublicPage(mixed $value): int
    {
        $page = max(1, (int) $value);

        return min($page, InputConstraints::PUBLIC_PAGE_MAX);
    }

    private function normalizeLongitude(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return $coordinate >= -180.0 && $coordinate <= 180.0 ? $coordinate : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, mixed>
     */
    private function runAgendaQuery(array $filters, ?string $userId, int $skip, int $limit, bool $useGeo): array
    {
        $confirmedEventIds = $this->resolveConfirmedEventIds($filters, $userId);
        if (is_array($confirmedEventIds) && $confirmedEventIds === []) {
            return [];
        }

        $pipeline = $this->buildAgendaPipeline($filters, $skip, $limit, $useGeo, $confirmedEventIds);

        /** @var Collection<int, EventOccurrence> $events */
        $events = EventOccurrence::raw(fn ($collection) => $collection->aggregate($pipeline));

        return $events->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, mixed>
     */
    private function runStreamQuery(array $filters, ?string $userId, Carbon $since, bool $useGeo): array
    {
        $confirmedEventIds = $this->resolveConfirmedEventIds($filters, $userId);
        if (is_array($confirmedEventIds) && $confirmedEventIds === []) {
            return [];
        }

        $pipeline = $this->buildStreamPipeline($filters, $since, $useGeo, $confirmedEventIds);

        /** @var Collection<int, EventOccurrence> $events */
        $events = EventOccurrence::raw(fn ($collection) => $collection->aggregate($pipeline));

        return $events->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildAgendaPipeline(
        array $filters,
        int $skip,
        int $limit,
        bool $useGeo,
        ?array $confirmedEventIds = null
    ): array {
        $now = new UTCDateTime(Carbon::now());
        $pipeline = [];

        $baseMatch = [
            'deleted_at' => null,
            'is_event_published' => true,
        ];
        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '' && ! $useGeo) {
            $baseMatch = [
                '$and' => [
                    $baseMatch,
                    $this->buildSearchMatchExpression($search),
                ],
            ];
        }

        if ($useGeo && $filters['origin_lat'] !== null && $filters['origin_lng'] !== null) {
            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $filters['origin_lng'], (float) $filters['origin_lat']],
                ],
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => [
                    ...$baseMatch,
                    'geo_location' => ['$ne' => null],
                ],
            ];

            if ($filters['max_distance_meters'] !== null) {
                $geoNear['maxDistance'] = (float) $filters['max_distance_meters'];
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        } else {
            $pipeline[] = ['$match' => $baseMatch];
        }

        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTagsFilter($pipeline, $filters['tags']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy']);
        $this->applyConfirmedEventsFilter($pipeline, $confirmedEventIds);

        $pipeline[] = [
            '$addFields' => [
                'effective_end' => [
                    '$ifNull' => [
                        '$ends_at',
                        [
                            '$add' => ['$starts_at', self::DEFAULT_EVENT_DURATION_MS],
                        ],
                    ],
                ],
            ],
        ];

        if ((bool) ($filters['live_now_only'] ?? false)) {
            $pipeline[] = [
                '$match' => [
                    '$expr' => [
                        '$and' => [
                            ['$lte' => ['$starts_at', $now]],
                            ['$gt' => ['$effective_end', $now]],
                        ],
                    ],
                ],
            ];
            $sort = ['starts_at' => 1, '_id' => 1];
        } elseif ($filters['past_only']) {
            $pipeline[] = ['$match' => ['$expr' => ['$lte' => ['$effective_end', $now]]]];
            $sort = ['starts_at' => -1, '_id' => -1];
        } else {
            $pipeline[] = ['$match' => ['$expr' => ['$gt' => ['$effective_end', $now]]]];
            $sort = ['starts_at' => 1, '_id' => 1];
        }

        $pipeline[] = ['$sort' => $sort];
        $pipeline[] = ['$skip' => $skip];
        $pipeline[] = ['$limit' => $limit];

        return $pipeline;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildStreamPipeline(
        array $filters,
        Carbon $since,
        bool $useGeo,
        ?array $confirmedEventIds = null
    ): array {
        $sinceUtc = new UTCDateTime($since);
        $pipeline = [];

        $baseMatch = [
            '$or' => [
                ['updated_at' => ['$gt' => $sinceUtc]],
                ['deleted_at' => ['$gt' => $sinceUtc]],
            ],
        ];
        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '' && ! $useGeo) {
            $baseMatch = [
                '$and' => [
                    $baseMatch,
                    $this->buildSearchMatchExpression($search),
                ],
            ];
        }

        if ($useGeo && $filters['origin_lat'] !== null && $filters['origin_lng'] !== null) {
            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $filters['origin_lng'], (float) $filters['origin_lat']],
                ],
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => [
                    'geo_location' => ['$ne' => null],
                ],
            ];

            if ($filters['max_distance_meters'] !== null) {
                $geoNear['maxDistance'] = (float) $filters['max_distance_meters'];
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        }

        $pipeline[] = ['$match' => $baseMatch];

        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTagsFilter($pipeline, $filters['tags']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy']);
        $this->applyConfirmedEventsFilter($pipeline, $confirmedEventIds);

        $pipeline[] = ['$sort' => ['updated_at' => 1, '_id' => 1]];
        $pipeline[] = ['$limit' => InputConstraints::PUBLIC_STREAM_DELTA_LIMIT];

        return $pipeline;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<int, string>  $categories
     */
    private function applyCategoryFilter(array &$pipeline, array $categories): void
    {
        if ($categories === []) {
            return;
        }

        $regexes = array_map(
            static fn (string $value): Regex => new Regex('^'.preg_quote($value).'$', 'i'),
            $categories
        );

        $pipeline[] = [
            '$match' => [
                '$or' => [
                    ['type.slug' => ['$in' => $regexes]],
                    ['categories' => ['$in' => $regexes]],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<int, string>  $tags
     */
    private function applyTagsFilter(array &$pipeline, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $regexes = array_map(
            static fn (string $value): Regex => new Regex('^'.preg_quote($value).'$', 'i'),
            $tags
        );

        $pipeline[] = [
            '$match' => [
                'tags' => ['$in' => $regexes],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<int, array{type: string, value: string}>  $taxonomy
     */
    private function applyTaxonomyFilter(array &$pipeline, array $taxonomy): void
    {
        if ($taxonomy === []) {
            return;
        }

        $termMatches = [];

        foreach ($taxonomy as $term) {
            $termMatches[] = [
                'venue.taxonomy_terms' => [
                    '$elemMatch' => [
                        'type' => $term['type'],
                        'value' => $term['value'],
                    ],
                ],
            ];

            $termMatches[] = [
                'event_parties' => [
                    '$elemMatch' => [
                        'metadata.taxonomy_terms' => [
                            '$elemMatch' => [
                                'type' => $term['type'],
                                'value' => $term['value'],
                            ],
                        ],
                    ],
                ],
            ];

            $termMatches[] = [
                'taxonomy_terms' => [
                    '$elemMatch' => [
                        'type' => $term['type'],
                        'value' => $term['value'],
                    ],
                ],
            ];
        }

        if ($termMatches !== []) {
            $pipeline[] = ['$match' => ['$or' => $termMatches]];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<int, string>|null  $confirmedEventIds
     */
    private function applyConfirmedEventsFilter(array &$pipeline, ?array $confirmedEventIds): void
    {
        if ($confirmedEventIds === null) {
            return;
        }

        $pipeline[] = [
            '$match' => [
                'event_id' => ['$in' => $confirmedEventIds],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function resolveAccountProfileIds(string $accountId): array
    {
        return $this->eventProfileResolver->listProfileIdsForAccount($accountId);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item): ?string {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }

            return trim($item);
        }, $items)));
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function normalizeTaxonomyArray(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = isset($item['type']) ? trim((string) $item['type']) : '';
            $value = isset($item['value']) ? trim((string) $item['value']) : '';

            if ($type === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'type' => $type,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private function extractSearchQuery(array $queryParams): ?string
    {
        $rawSearch = $queryParams['search'] ?? $queryParams['q'] ?? null;
        if (! is_string($rawSearch)) {
            return null;
        }
        $trimmed = trim($rawSearch);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSearchMatchExpression(string $searchQuery): array
    {
        $regex = $this->buildContainsRegexPattern($searchQuery);

        return [
            '$or' => [
                ['title' => ['$regex' => $regex, '$options' => 'i']],
                ['slug' => ['$regex' => $regex, '$options' => 'i']],
                ['content' => ['$regex' => $regex, '$options' => 'i']],
                ['tags' => ['$regex' => $regex, '$options' => 'i']],
                ['categories' => ['$regex' => $regex, '$options' => 'i']],
                ['taxonomy_terms.value' => ['$regex' => $regex, '$options' => 'i']],
                ['event_parties.metadata.display_name' => ['$regex' => $regex, '$options' => 'i']],
            ],
        ];
    }

    private function buildContainsRegexPattern(string $searchQuery): string
    {
        $escaped = preg_quote(trim($searchQuery), '/');

        return $escaped;
    }

    private function resolveMaxDistanceMeters(array $queryParams): float
    {
        $settings = $this->resolveRadiusSettings();
        $requestedMeters = Arr::get($queryParams, 'max_distance_meters');

        $requestedKm = $requestedMeters !== null
            ? ((float) $requestedMeters / 1000)
            : $settings['default_km'];

        $boundedKm = min(max($requestedKm, $settings['min_km']), $settings['max_km']);

        return $boundedKm * 1000;
    }

    /**
     * @return array{min_km: float, default_km: float, max_km: float}
     */
    private function resolveRadiusSettings(): array
    {
        return $this->eventRadiusSettings->resolveRadiusSettings();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, string>|null
     */
    private function resolveConfirmedEventIds(array $filters, ?string $userId): ?array
    {
        if ((bool) ($filters['confirmed_only'] ?? false) !== true) {
            return null;
        }

        if (! is_string($userId) || trim($userId) === '') {
            return [];
        }

        return array_values(array_unique(array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $this->eventAttendanceRead->listConfirmedEventIdsForUser($userId)),
            static fn (string $value): bool => $value !== ''
        ))));
    }

    private function parseSince(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function formatEvent(
        mixed $event,
        ?string $userId = null,
        bool $includeArtists = true
    ): array
    {
        $type = $this->normalizeArray($event->type ?? null);
        $location = $this->normalizeArray($event->location ?? []);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray($event->thumb ?? null)
        );
        $eventParties = $this->normalizeEventParties($event->event_parties ?? []);
        $artists = $includeArtists
            ? $this->resolveArtistsReadProjection($eventParties)
            : [];
        $tags = $this->normalizeArray($event->tags ?? []);
        $taxonomyTerms = $this->ensureTaxonomySnapshots($event->taxonomy_terms ?? []);

        $venueDisplay = $this->scalarString($venue['display_name'] ?? null)
            ?? $this->scalarString($venue['name'] ?? null);
        $venuePayload = $venue === [] ? null : [
            'id' => $this->resolveLegacyDocumentId($venue),
            'display_name' => $venueDisplay ?? '',
            'slug' => $this->scalarString($venue['slug'] ?? null),
            'profile_type' => $this->scalarString($venue['profile_type'] ?? null),
            'tagline' => $this->scalarString($venue['tagline'] ?? null),
            'hero_image_url' => $this->absoluteUrlString($venue['hero_image_url'] ?? null),
            'logo_url' => $this->absoluteUrlString($venue['logo_url'] ?? null),
            'avatar_url' => $this->absoluteUrlString($venue['avatar_url'] ?? null)
                ?? $this->absoluteUrlString($venue['logo_url'] ?? null),
            'cover_url' => $this->absoluteUrlString($venue['cover_url'] ?? null)
                ?? $this->absoluteUrlString($venue['hero_image_url'] ?? null),
            'taxonomy_terms' => $this->ensureTaxonomySnapshots($venue['taxonomy_terms'] ?? []),
        ];

        $geo = $this->normalizeArray($location['geo'] ?? $event->geo_location ?? null);
        $coordinates = $geo['coordinates'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];
        }

        $occurrences = $this->resolveEventOccurrences($event);
        $capabilities = $this->resolveEventCapabilities($event);
        $createdBy = $this->normalizeArray($event->created_by ?? []);
        $linkedAccountProfiles = $this->resolveLinkedAccountProfiles($eventParties);
        $typeVisual = $this->normalizeEventTypeVisual(
            $this->normalizeArray($type['visual'] ?? $type['poi_visual'] ?? null)
        );

        $isOccurrence = isset($event->event_id) && (string) $event->event_id !== '';
        $eventId = $isOccurrence ? (string) $event->event_id : (isset($event->_id) ? (string) $event->_id : '');
        $occurrenceId = $isOccurrence && isset($event->_id) ? (string) $event->_id : null;
        $startAt = $isOccurrence
            ? $this->formatDate($this->extractRawAttribute($event, 'starts_at'))
            : $this->formatDate($this->extractRawAttribute($event, 'date_time_start'));
        $endAt = $isOccurrence
            ? $this->formatDate($this->extractRawAttribute($event, 'ends_at'))
            : $this->formatDate($this->extractRawAttribute($event, 'date_time_end'));

        $payload = [
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'slug' => $this->scalarString($event->slug ?? null) ?? '',
            'type' => [
                'id' => $this->resolveLegacyDocumentId($type),
                'name' => $this->scalarString($type['name'] ?? null) ?? '',
                'slug' => $this->scalarString($type['slug'] ?? null) ?? '',
                'description' => $this->scalarString($type['description'] ?? null),
                'visual' => $typeVisual,
                'poi_visual' => $typeVisual,
                'icon' => $this->scalarString($type['icon'] ?? null),
                'color' => $this->scalarString($type['color'] ?? null),
                'icon_color' => $this->scalarString($type['icon_color'] ?? null),
            ],
            'title' => $this->scalarString($event->title ?? null) ?? '',
            'content' => $this->scalarString($event->content ?? null) ?? '',
            'location' => $location === [] ? null : $location,
            'place_ref' => $placeRef === [] ? null : $placeRef,
            'venue' => $venuePayload,
            'latitude' => $lat,
            'longitude' => $lng,
            'thumb' => $thumb,
            'date_time_start' => $startAt,
            'date_time_end' => $endAt,
            'occurrences' => $occurrences,
            'created_by' => [
                'type' => $this->scalarString($createdBy['type'] ?? null) ?? '',
                'id' => $this->scalarString($createdBy['id'] ?? null) ?? '',
            ],
            'event_parties' => $eventParties,
            'linked_account_profiles' => $linkedAccountProfiles,
            'programming_items' => $this->normalizeProgrammingItems($event->programming_items ?? []),
            'capabilities' => $capabilities,
            'tags' => array_values(array_map('strval', $tags)),
            'taxonomy_terms' => $taxonomyTerms,
        ];

        if ($includeArtists) {
            $payload['artists'] = $artists;
        }

        return $payload;
    }

    /**
     * @return array{event_id: string, occurrence_id: string, type: string, updated_at: string}
     */
    private function formatStreamDelta(mixed $event, Carbon $since): array
    {
        $updatedAt = $this->formatDate($event->updated_at ?? null);
        $deletedAt = $this->formatDate($event->deleted_at ?? null);
        $createdAt = $this->formatDate($event->created_at ?? null);
        $isPublished = (bool) ($event->is_event_published ?? false);

        $type = null;
        if ($deletedAt !== null || ! $isPublished) {
            $type = 'occurrence.deleted';
        } elseif ($createdAt !== null) {
            $created = Carbon::parse($createdAt);
            if ($created->greaterThan($since)) {
                $type = 'occurrence.created';
            } else {
                $type = 'occurrence.updated';
            }
        }

        return [
            'event_id' => (string) ($event->event_id ?? ''),
            'occurrence_id' => isset($event->_id) ? (string) $event->_id : '',
            'type' => $type ?? 'occurrence.updated',
            'updated_at' => $updatedAt ?? $deletedAt ?? $createdAt ?? Carbon::now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $visual
     * @return array<string, mixed>|null
     */
    private function normalizeEventTypeVisual(array $visual): ?array
    {
        if ($visual === []) {
            return null;
        }

        $mode = $this->scalarString($visual['mode'] ?? null);
        if ($mode === 'icon') {
            return [
                'mode' => 'icon',
                'icon' => $this->scalarString($visual['icon'] ?? null),
                'color' => $this->scalarString($visual['color'] ?? null),
                'icon_color' => $this->scalarString($visual['icon_color'] ?? null),
            ];
        }

        if ($mode === 'image') {
            return [
                'mode' => 'image',
                'image_source' => $this->scalarString($visual['image_source'] ?? null),
                'image_url' => $this->absoluteUrlString($visual['image_url'] ?? null),
            ];
        }

        return null;
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

    /**
     * @return array<int, mixed>|array<string, mixed>|null
     */
    private function normalizeNullableArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return $this->normalizeArray($value);
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

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_int($value) || is_float($value)) {
            $numeric = (float) $value;
            if (abs($numeric) >= 100000000000) {
                return Carbon::createFromTimestampMsUTC((int) round($numeric))->format(DATE_ATOM);
            }

            return Carbon::createFromTimestampUTC((int) round($numeric))->format(DATE_ATOM);
        }
        $normalized = $this->normalizeArray($value);
        if ($normalized !== []) {
            $candidate = $normalized['$date'] ?? $normalized['date'] ?? null;
            if ($candidate !== null && $candidate !== $value) {
                return $this->formatDate($candidate);
            }
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->format(DATE_ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }

    /**
     * @return array<int, string|ObjectId>
     */
    private function buildProfileIdCandidates(string $profileId): array
    {
        $candidates = [$profileId];

        if ($this->looksLikeObjectId($profileId)) {
            $candidates[] = new ObjectId($profileId);
        }

        return $candidates;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string|ObjectId>
     */
    private function buildProfileIdCandidatesFromList(array $profileIds): array
    {
        $candidates = [];
        foreach ($profileIds as $profileId) {
            foreach ($this->buildProfileIdCandidates($profileId) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, string>  $eventIds
     * @return array<string, iterable<int, EventOccurrence>>
     */
    private function loadOccurrencesByEventIds(array $eventIds): array
    {
        $normalized = array_values(array_filter(array_unique(array_map(
            static fn (mixed $eventId): string => trim((string) $eventId),
            $eventIds
        ))));

        if ($normalized === []) {
            return [];
        }

        EventBus::dispatch('belluga.events.management_occurrence_bulk_load', [
            $normalized,
        ]);

        return EventOccurrence::query()
            ->whereIn('event_id', $normalized)
            ->orderBy('starts_at')
            ->get()
            ->groupBy(static fn (EventOccurrence $occurrence): string => (string) ($occurrence->event_id ?? ''))
            ->all();
    }

    /**
     * @param  array<int, string>  $eventIds
     * @return array<int, string|ObjectId>
     */
    private function buildEventIdCandidates(array $eventIds): array
    {
        $candidates = [];
        foreach ($eventIds as $eventId) {
            $normalized = trim($eventId);
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

    private function resolveSelectedOccurrence(
        Event $event,
        ?string $occurrenceRef,
        ?iterable $preloadedOccurrences = null
    ): ?EventOccurrence
    {
        $documents = $preloadedOccurrences === null
            ? $this->loadEventOccurrenceDocuments($event)
            : collect($preloadedOccurrences);

        if ($documents->isEmpty()) {
            return null;
        }

        $normalizedRef = is_string($occurrenceRef) ? trim($occurrenceRef) : '';
        if ($normalizedRef !== '') {
            foreach ($documents as $document) {
                $documentId = isset($document->_id) ? (string) $document->_id : '';
                $documentSlug = isset($document->occurrence_slug) ? (string) $document->occurrence_slug : '';
                if ($normalizedRef === $documentId || $normalizedRef === $documentSlug) {
                    return $document;
                }
            }
        }

        $now = Carbon::now();
        foreach ($documents as $document) {
            $start = $this->toCarbon($this->extractRawAttribute($document, 'starts_at'));
            if (! $start || $start->greaterThan($now)) {
                continue;
            }

            $end = $this->toCarbon($this->extractRawAttribute($document, 'effective_ends_at'))
                ?? $this->toCarbon($this->extractRawAttribute($document, 'ends_at'))
                ?? $start->copy()->addHours(3);

            if ($end->greaterThan($now)) {
                return $document;
            }
        }

        foreach ($documents as $document) {
            $start = $this->toCarbon($this->extractRawAttribute($document, 'starts_at'));
            if ($start && $start->greaterThanOrEqualTo($now)) {
                return $document;
            }
        }

        return $documents->first();
    }

    private function loadEventOccurrenceDocuments(Event $event): mixed
    {
        $eventId = isset($event->_id) ? (string) $event->_id : '';
        if ($eventId === '') {
            return collect();
        }

        EventBus::dispatch('belluga.events.detail_occurrences_load', [$eventId]);

        return EventOccurrence::query()
            ->where('event_id', $eventId)
            ->orderBy('starts_at')
            ->get();
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_int($value) || is_float($value)) {
            return abs((float) $value) >= 100000000000
                ? Carbon::createFromTimestampMsUTC((int) round((float) $value))
                : Carbon::createFromTimestampUTC((int) round((float) $value));
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  iterable<int, EventOccurrence>|null  $preloadedOccurrences
     * @return array<int, array<string, mixed>>
     */
    private function resolveEventOccurrences(
        mixed $event,
        ?string $selectedOccurrenceId = null,
        ?iterable $preloadedOccurrences = null
    ): array
    {
        if (isset($event->event_id) && (string) $event->event_id !== '') {
            $start = $this->formatDate($this->extractRawAttribute($event, 'starts_at'));
            if ($start === null) {
                return [];
            }

            $occurrenceId = isset($event->_id) ? (string) $event->_id : null;
            $programmingItems = $this->normalizeProgrammingItems($event->programming_items ?? []);

            return [[
                'occurrence_id' => $occurrenceId,
                'occurrence_slug' => isset($event->occurrence_slug) ? (string) $event->occurrence_slug : null,
                'date_time_start' => $start,
                'date_time_end' => $this->formatDate($this->extractRawAttribute($event, 'ends_at')),
                'is_selected' => true,
                'has_location_override' => false,
                'location_override' => null,
                'own_event_parties' => $this->normalizeEventParties($event->own_event_parties ?? []),
                'own_linked_account_profiles' => $this->normalizeLinkedAccountProfileSummaries($event->own_linked_account_profiles ?? []),
                'programming_items' => $programmingItems,
                'programming_count' => count($programmingItems),
            ]];
        }

        $eventId = isset($event->_id) ? (string) $event->_id : '';
        if ($eventId !== '') {
            $documents = $preloadedOccurrences === null
                ? EventOccurrence::query()
                    ->where('event_id', $eventId)
                    ->orderBy('starts_at')
                    ->get()
                : collect($preloadedOccurrences);

            if ($documents->isNotEmpty()) {
                return $documents->map(function (EventOccurrence $occurrence) use ($selectedOccurrenceId): array {
                    $occurrenceId = isset($occurrence->_id) ? (string) $occurrence->_id : null;
                    $programmingItems = $this->normalizeProgrammingItems($occurrence->programming_items ?? []);

                    return [
                        'occurrence_id' => $occurrenceId,
                        'occurrence_slug' => isset($occurrence->occurrence_slug) ? (string) $occurrence->occurrence_slug : null,
                        'date_time_start' => $this->formatDate($this->extractRawAttribute($occurrence, 'starts_at')),
                        'date_time_end' => $this->formatDate($this->extractRawAttribute($occurrence, 'ends_at')),
                        'is_selected' => $selectedOccurrenceId !== null && $occurrenceId === $selectedOccurrenceId,
                        'has_location_override' => false,
                        'location_override' => null,
                        'own_event_parties' => $this->normalizeEventParties($occurrence->own_event_parties ?? []),
                        'own_linked_account_profiles' => $this->normalizeLinkedAccountProfileSummaries($occurrence->own_linked_account_profiles ?? []),
                        'programming_items' => $programmingItems,
                        'programming_count' => count($programmingItems),
                    ];
                })->filter(static fn (array $item): bool => $item['date_time_start'] !== null)->values()->all();
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEventCapabilities(mixed $event): array
    {
        $raw = $this->normalizeArray($event->capabilities ?? []);
        $mapPoi = $this->normalizeArray($raw['map_poi'] ?? []);
        $mapPoiEventEnabled = (bool) ($mapPoi['enabled'] ?? true);
        $mapPoiDiscoveryScope = $this->normalizeArray($mapPoi['discovery_scope'] ?? null);
        if ($mapPoiDiscoveryScope === []) {
            $mapPoiDiscoveryScope = null;
        }

        $tenantCapabilities = $this->resolveTenantCapabilities();
        $tenantMapPoi = $this->normalizeArray($tenantCapabilities['map_poi'] ?? []);
        $tenantMapPoiAvailable = (bool) ($tenantMapPoi['available'] ?? true);

        $capabilities = [];
        if ($tenantMapPoiAvailable) {
            $capabilities['map_poi'] = [
                'enabled' => $mapPoiEventEnabled,
            ];
            if ($mapPoiDiscoveryScope !== null) {
                $capabilities['map_poi']['discovery_scope'] = $mapPoiDiscoveryScope;
            }
        }

        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTenantCapabilities(): array
    {
        if ($this->tenantCapabilitiesCache !== null) {
            return $this->tenantCapabilitiesCache;
        }

        $raw = $this->eventCapabilitySettings->resolveTenantCapabilities();
        $this->tenantCapabilitiesCache = is_array($raw) ? $raw : [];

        return $this->tenantCapabilitiesCache;
    }

    private function isAccountOwner(Event $event, string $actorUserId): bool
    {
        $createdBy = $this->normalizeArray($event->created_by ?? []);
        $createdByType = (string) ($createdBy['type'] ?? '');
        $createdById = (string) ($createdBy['id'] ?? '');

        return $createdByType === 'account_user' && $createdById !== '' && $createdById === $actorUserId;
    }

    private function applyAccountFiltersToQuery($query, string $accountId): void
    {
        if ($accountId === '') {
            return;
        }

        $query->where('account_context_ids', $accountId);
    }

    /**
     * @return array<int, array{
     *   party_type: string,
     *   party_ref_id: string,
     *   permissions: array{can_edit: bool},
     *   metadata?: array<string, mixed>
     * }>
     */
    private function normalizeEventParties(mixed $value): array
    {
        $rows = $this->normalizeArray($value);
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $partyType = trim((string) ($row['party_type'] ?? ''));
            $partyRefId = trim((string) ($row['party_ref_id'] ?? ''));
            if ($partyType === '' || $partyRefId === '') {
                continue;
            }

            $permissions = isset($row['permissions']) && is_array($row['permissions'])
                ? $row['permissions']
                : [];
            $metadata = isset($row['metadata']) && is_array($row['metadata'])
                ? $row['metadata']
                : null;
            if ($metadata !== null && array_key_exists('taxonomy_terms', $metadata)) {
                $metadata['taxonomy_terms'] = $this->ensureTaxonomySnapshots($metadata['taxonomy_terms']);
            }

            $item = [
                'party_type' => $partyType,
                'party_ref_id' => $partyRefId,
                'permissions' => [
                    'can_edit' => (bool) ($permissions['can_edit'] ?? false),
                ],
            ];

            if ($metadata !== null && $metadata !== []) {
                $item['metadata'] = $metadata;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }


    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function resolveArtistsReadProjection(array $eventParties): array
    {
        return array_values(array_map(function (array $party): array {
            $metadata = isset($party['metadata']) && is_array($party['metadata'])
                ? $party['metadata']
                : [];

            return [
                'id' => $this->scalarString($party['party_ref_id'] ?? null) ?? '',
                'display_name' => $this->scalarString($metadata['display_name'] ?? null) ?? '',
                'slug' => $this->scalarString($metadata['slug'] ?? null),
                'profile_type' => $this->scalarString($metadata['profile_type'] ?? null)
                    ?? $this->scalarString($party['party_type'] ?? null)
                    ?? '',
                'avatar_url' => $this->absoluteUrlString($metadata['avatar_url'] ?? null),
                'cover_url' => $this->absoluteUrlString($metadata['cover_url'] ?? null),
                'highlight' => false,
                'genres' => array_values($this->normalizeStringArray($metadata['genres'] ?? [])),
                'taxonomy_terms' => $this->ensureTaxonomySnapshots($metadata['taxonomy_terms'] ?? []),
            ];
        }, array_values(array_filter($eventParties, function (array $party): bool {
            $partyType = trim((string) ($party['party_type'] ?? ''));
            $metadata = isset($party['metadata']) && is_array($party['metadata'])
                ? $party['metadata']
                : [];

            return $partyType !== 'venue'
                && trim((string) ($party['party_ref_id'] ?? '')) !== ''
                && trim((string) ($metadata['display_name'] ?? '')) !== '';
        }))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function resolveLinkedAccountProfiles(array $eventParties): array {
        $items = [];
        $seenIds = [];

        $push = function (array $payload) use (&$items, &$seenIds): void {
            $id = trim((string) ($this->scalarString($payload['id'] ?? null) ?? ''));
            $displayName = trim((string) ($this->scalarString($payload['display_name'] ?? null) ?? ''));
            $profileType = trim((string) ($this->scalarString($payload['profile_type'] ?? null) ?? ''));

            if ($id === '' || $displayName === '' || $profileType === '' || isset($seenIds[$id])) {
                return;
            }

            $items[] = [
                'id' => $id,
                'display_name' => $displayName,
                'slug' => $this->scalarString($payload['slug'] ?? null),
                'profile_type' => $profileType,
                'party_type' => $this->scalarString($payload['party_type'] ?? null),
                'avatar_url' => $this->absoluteUrlString($payload['avatar_url'] ?? null),
                'cover_url' => $this->absoluteUrlString($payload['cover_url'] ?? null),
                'taxonomy_terms' => $this->ensureTaxonomySnapshots($payload['taxonomy_terms'] ?? []),
            ];
            $seenIds[$id] = true;
        };

        foreach ($eventParties as $party) {
            $metadata = isset($party['metadata']) && is_array($party['metadata'])
                ? $party['metadata']
                : [];

            $push([
                'id' => $party['party_ref_id'] ?? '',
                'display_name' => $metadata['display_name'] ?? '',
                'slug' => $metadata['slug'] ?? null,
                'profile_type' => $metadata['profile_type'] ?? null,
                'party_type' => $party['party_type'] ?? null,
                'avatar_url' => $metadata['avatar_url'] ?? null,
                'cover_url' => $metadata['cover_url'] ?? null,
                'taxonomy_terms' => $metadata['taxonomy_terms'] ?? [],
            ]);
        }

        return $items;
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLinkedAccountProfileSummaries(mixed $profiles): array
    {
        $rows = $this->normalizeArray($profiles);
        if ($rows === []) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            $profile = $this->normalizeArray($row);
            if ($profile === []) {
                continue;
            }

            if (array_key_exists('taxonomy_terms', $profile)) {
                $profile['taxonomy_terms'] = $this->ensureTaxonomySnapshots($profile['taxonomy_terms']);
            }

            $normalized[] = $profile;
        }

        return $normalized;
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

            $normalized[] = [
                'time' => $this->scalarString($item['time'] ?? null) ?? '',
                'title' => $this->scalarString($item['title'] ?? null),
                'account_profile_ids' => array_values(array_map('strval', $this->normalizeArray($item['account_profile_ids'] ?? []))),
                'linked_account_profiles' => $this->normalizeLinkedAccountProfileSummaries($item['linked_account_profiles'] ?? []),
                'place_ref' => $this->normalizeNullableArray($item['place_ref'] ?? null),
                'location_profile' => $this->normalizeLinkedAccountProfileSummary($item['location_profile'] ?? null),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeLinkedAccountProfileSummary(mixed $profile): ?array
    {
        $payload = $this->normalizeArray($profile);
        if ($payload === []) {
            return null;
        }

        if (array_key_exists('taxonomy_terms', $payload)) {
            $payload['taxonomy_terms'] = $this->ensureTaxonomySnapshots($payload['taxonomy_terms']);
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventLinkedProfiles
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    private function resolveDetailLinkedAccountProfiles(array $eventLinkedProfiles, array $occurrences): array
    {
        $profiles = [];
        $seenIds = [];

        $push = function (mixed $profile) use (&$profiles, &$seenIds): void {
            $normalized = $this->normalizeLinkedAccountProfileSummary($profile);
            if ($normalized === null) {
                return;
            }

            $id = trim((string) ($this->scalarString($normalized['id'] ?? null) ?? ''));
            $displayName = trim((string) ($this->scalarString($normalized['display_name'] ?? null) ?? ''));
            $profileType = trim((string) ($this->scalarString($normalized['profile_type'] ?? null) ?? ''));
            if ($id === '' || $displayName === '' || $profileType === '' || isset($seenIds[$id])) {
                return;
            }

            $profiles[] = [
                'id' => $id,
                'display_name' => $displayName,
                'slug' => $this->scalarString($normalized['slug'] ?? null),
                'profile_type' => $profileType,
                'party_type' => $this->scalarString($normalized['party_type'] ?? null),
                'avatar_url' => $this->absoluteUrlString($normalized['avatar_url'] ?? null),
                'cover_url' => $this->absoluteUrlString($normalized['cover_url'] ?? null),
                'taxonomy_terms' => $this->ensureTaxonomySnapshots($normalized['taxonomy_terms'] ?? []),
            ];
            $seenIds[$id] = true;
        };

        foreach ($this->normalizeLinkedAccountProfileSummaries($eventLinkedProfiles) as $profile) {
            $push($profile);
        }

        foreach ($occurrences as $occurrence) {
            foreach ($this->normalizeLinkedAccountProfileSummaries($occurrence['own_linked_account_profiles'] ?? []) as $profile) {
                $push($profile);
            }

            foreach ($this->normalizeProgrammingItems($occurrence['programming_items'] ?? []) as $item) {
                foreach ($this->normalizeLinkedAccountProfileSummaries($item['linked_account_profiles'] ?? []) as $profile) {
                    $push($profile);
                }
            }
        }

        return $profiles;
    }

    /**
     * @param  array<int, array<string, mixed>>  $linkedProfiles
     * @return array<int, array<string, mixed>>
     */
    private function resolveArtistsReadProjectionFromLinkedProfiles(array $linkedProfiles): array
    {
        return array_values(array_map(function (array $profile): array {
            return [
                'id' => $this->scalarString($profile['id'] ?? null) ?? '',
                'display_name' => $this->scalarString($profile['display_name'] ?? null) ?? '',
                'slug' => $this->scalarString($profile['slug'] ?? null),
                'profile_type' => $this->scalarString($profile['profile_type'] ?? null) ?? '',
                'avatar_url' => $this->absoluteUrlString($profile['avatar_url'] ?? null),
                'cover_url' => $this->absoluteUrlString($profile['cover_url'] ?? null),
                'highlight' => false,
                'genres' => [],
                'taxonomy_terms' => $this->ensureTaxonomySnapshots($profile['taxonomy_terms'] ?? []),
            ];
        }, array_values(array_filter($this->normalizeLinkedAccountProfileSummaries($linkedProfiles), function (array $profile): bool {
            $id = trim((string) ($this->scalarString($profile['id'] ?? null) ?? ''));
            $displayName = trim((string) ($this->scalarString($profile['display_name'] ?? null) ?? ''));
            $profileType = trim((string) ($this->scalarString($profile['profile_type'] ?? null) ?? ''));
            $partyType = trim((string) ($this->scalarString($profile['party_type'] ?? null) ?? ''));

            return $id !== ''
                && $displayName !== ''
                && $profileType !== 'venue'
                && $partyType !== 'venue';
        }))));
    }

    /**
     * @param  iterable<int, EventOccurrence>|null  $preloadedOccurrences
     * @return array<int, array<string, mixed>>
     */
    private function resolveOccurrenceOwnedEventParties(Event $event, ?iterable $preloadedOccurrences = null): array
    {
        $eventId = isset($event->_id) ? (string) $event->_id : '';
        if ($eventId === '') {
            return [];
        }

        $rows = [];
        $documents = $preloadedOccurrences === null
            ? EventOccurrence::query()
                ->where('event_id', $eventId)
                ->orderBy('starts_at')
                ->get()
            : collect($preloadedOccurrences);

        foreach ($documents as $document) {
            foreach ($this->normalizeEventParties($document->own_event_parties ?? []) as $party) {
                $rows[] = $party;
            }
        }

        return $rows;
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
                $partyType = trim((string) ($row['party_type'] ?? ''));
                $partyRefId = trim((string) ($row['party_ref_id'] ?? ''));
                if ($partyType === '' || $partyRefId === '') {
                    continue;
                }

                $key = "{$partyType}:{$partyRefId}";
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
     * @param  array<int, string>  $profileIds
     */
    private function eventReferencesPlaceRefProfile(Event $event, array $profileIds): bool
    {
        $placeRefId = $this->resolvePlaceRefId(
            $this->normalizeArray($event->place_ref ?? null)
        );
        if ($placeRefId === '') {
            return false;
        }

        return in_array($placeRefId, $profileIds, true);
    }

    /**
     * @param  array<string, mixed>  $placeRef
     * @return array<string, mixed>
     */
    private function normalizePlaceRefPayload(array $placeRef): array
    {
        if ($placeRef === []) {
            return [];
        }

        $normalized = $placeRef;
        $placeRefId = $this->resolvePlaceRefId($placeRef);
        if ($placeRefId !== '') {
            $normalized['id'] = $placeRefId;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $placeRef
     */
    private function resolvePlaceRefId(array $placeRef): string
    {
        return $this->resolveLegacyDocumentId($placeRef);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function resolveLegacyDocumentId(array $document): string
    {
        $rawId = $document['id'] ?? $document['_id'] ?? null;

        if ($rawId instanceof ObjectId) {
            return (string) $rawId;
        }

        if (is_array($rawId)) {
            $legacyOid = trim((string) ($rawId['$oid'] ?? $rawId['oid'] ?? ''));
            if ($legacyOid !== '') {
                return $legacyOid;
            }
        }

        return trim((string) $rawId);
    }

    /**
     * @param  array<string, mixed>  $thumb
     * @return array<string, mixed>|null
     */
    private function normalizeThumbPayload(array $thumb): ?array
    {
        if ($thumb === []) {
            return null;
        }

        $type = $this->scalarString($thumb['type'] ?? null);
        $thumbData = $this->normalizeArray($thumb['data'] ?? null);
        $url = $this->absoluteUrlString($thumbData['url'] ?? $thumb['url'] ?? $thumb['uri'] ?? null);

        if ($url === null) {
            return null;
        }

        $payload = [];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $payload['data'] = ['url' => $url];

        return $payload;
    }

    private function scalarString(mixed $value): ?string
    {
        if ($value instanceof ObjectId) {
            return (string) $value;
        }

        $normalized = $this->normalizeArray($value);
        if ($normalized !== []) {
            $oid = $normalized['$oid'] ?? $normalized['oid'] ?? null;
            if ($oid !== null) {
                $oidString = trim((string) $oid);

                return $oidString !== '' ? $oidString : null;
            }

            return null;
        }

        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $normalizedScalar = trim((string) $value);

        return $normalizedScalar !== '' ? $normalizedScalar : null;
    }

    private function absoluteUrlString(mixed $value): ?string
    {
        $normalized = $this->scalarString($value);
        if ($normalized === null) {
            return null;
        }

        $parsed = parse_url($normalized);
        if (! is_array($parsed)) {
            return null;
        }

        $scheme = strtolower(trim((string) ($parsed['scheme'] ?? '')));
        $host = trim((string) ($parsed['host'] ?? ''));
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        return $normalized;
    }

    private function extractRawAttribute(mixed $model, string $attribute): mixed
    {
        if (is_object($model) && method_exists($model, 'getAttributes')) {
            $attributes = $model->getAttributes();
            if (is_array($attributes) && array_key_exists($attribute, $attributes)) {
                return $attributes[$attribute];
            }
        }

        if (is_array($model) && array_key_exists($attribute, $model)) {
            return $model[$attribute];
        }

        return is_object($model) ? ($model->{$attribute} ?? null) : null;
    }

    private function applyPublicPublicationFilter($query): void
    {
        $now = Carbon::now();

        $query->where(function ($builder) {
            $builder->where('publication.status', 'published')
                ->orWhereNull('publication.status');
        });

        $query->where(function ($builder) use ($now) {
            $builder->whereNull('publication.publish_at')
                ->orWhere('publication.publish_at', '<=', $now);
        });
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
