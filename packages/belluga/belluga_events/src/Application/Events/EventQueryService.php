<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use Belluga\Events\Contracts\EventAttendanceReadContract;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventDiscoveryFilterCatalogContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;
use Belluga\Events\Exceptions\EventNotPubliclyVisibleException;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
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
        private readonly EventAccountResolverContract $eventAccountResolver,
        private readonly EventRadiusSettingsContract $eventRadiusSettings,
        private readonly EventCapabilitySettingsContract $eventCapabilitySettings,
        private readonly EventAttendanceReadContract $eventAttendanceRead,
        private readonly EventTaxonomySnapshotResolverContract $taxonomySnapshotResolver,
        private readonly EventHeroImageResolver $eventHeroImages,
        private readonly EventProfileGroupMemberStore $profileGroupMemberStore,
        private readonly EventOccurrenceNestedAccountStore $occurrenceNestedAccountStore,
        private readonly EventDiscoveryFilterCatalogContract $eventDiscoveryFilterCatalog,
        ?EventManagementOccurrenceQuery $managementOccurrenceQuery = null,
    ) {
        $this->managementOccurrenceQuery = $managementOccurrenceQuery
            ?? new EventManagementOccurrenceQuery(
                $this->occurrenceNestedAccountStore,
                $this->eventProfileResolver,
                $this->eventAccountResolver,
            );
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

        app(TenantRequestLifecycleTrace::class)->record('endpoint.agenda.selection_catalog.start');
        $runtimeCatalog = $this->eventDiscoveryFilterCatalog->buildCanonicalCatalog(
            'home.events',
            is_array($raw['discovery_filter_facets'] ?? null)
                ? $raw['discovery_filter_facets']
                : null,
            request()->getSchemeAndHttpHost()
        );
        $repairedSelection = $this->eventDiscoveryFilterCatalog
            ->repairSelectionAgainstCanonicalCatalog([
                'primary' => $filters['categories'],
                'taxonomy' => $this->groupTaxonomySelectionsByType($filters['taxonomy']),
            ], $runtimeCatalog);
        app(TenantRequestLifecycleTrace::class)->record('endpoint.agenda.selection_catalog.complete');

        if ($repairedSelection['changed']) {
            $filters['categories'] = $repairedSelection['primary'];
            $filters['taxonomy'] = $this->flattenTaxonomySelectionMap(
                $repairedSelection['taxonomy']
            );
            $raw = $this->runAgendaQuery($filters, $userId, $skip, $limit, $useGeo);
        }

        $pageRows = $raw['page_rows'] ?? [];
        $hasMore = count($pageRows) > $pageSize;
        $pageSlice = array_slice($pageRows, 0, $pageSize);

        app(TenantRequestLifecycleTrace::class)->record('endpoint.agenda.hydration.start');
        $items = $this->formatAgendaEvents($pageSlice, $userId);
        app(TenantRequestLifecycleTrace::class)->record('endpoint.agenda.hydration.complete');

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'discovery_filter_facets' => $raw['discovery_filter_facets']
                ?? $this->emptyAgendaDiscoveryFilterFacetsPayload(),
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
        $relatedAccountProfileId = $this->extractManagementProfileFilterId($queryParams, 'related_account_profile_id');

        if (! $includeArchived && ($temporalBuckets !== [] || $specificDate !== null || $relatedAccountProfileId !== null)) {
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
        $hydrationDocuments = $events->all();
        foreach ($occurrencesByEventId as $occurrences) {
            foreach ($occurrences as $occurrence) {
                $hydrationDocuments[] = $occurrence;
            }
        }
        $hydrationContext = [
            'related_profiles' => $this->resolveCurrentRelatedProfilesForReadDocuments(
                $hydrationDocuments,
                ! $isAdminContext,
            ),
            'physical_hosts' => $this->resolveCurrentPhysicalHostsForReadDocuments(
                $hydrationDocuments,
                ! $isAdminContext,
            ),
        ];

        $paginator->setCollection(
            $events->map(
                fn (Event $event): array => $isAdminContext
                    ? $this->formatManagementEventList($event, $occurrencesByEventId, $hydrationContext)
                    : $this->formatPublicEventList($event, $occurrencesByEventId, $hydrationContext)
            )
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
     * @param  array<string, mixed>  $venue
     * @return array<string, mixed>|null
     */
    private function formatVenuePreviewPayload(array $venue): ?array
    {
        if ($venue === []) {
            return null;
        }

        $venueDisplay = $this->scalarString($venue['display_name'] ?? null)
            ?? $this->scalarString($venue['name'] ?? null);
        $venueSlug = $this->scalarString($venue['slug'] ?? null);
        $venueProfileType = $this->scalarString($venue['profile_type'] ?? null);
        $supportsPublicNavigation = array_key_exists('supports_public_navigation', $venue)
            ? (bool) ($venue['supports_public_navigation'] ?? false)
            : ($venueProfileType !== null
                && $venueProfileType !== ''
                && $this->eventProfileResolver->isProfileTypePubliclyNavigable($venueProfileType));
        $venueCanOpenPublicDetail = array_key_exists('can_open_public_detail', $venue)
            ? (bool) ($venue['can_open_public_detail'] ?? false)
            : ($venueSlug !== null
                && $venueSlug !== ''
                && $supportsPublicNavigation);
        $avatarUrl = $this->absoluteUrlString($venue['avatar_url'] ?? null)
            ?? $this->absoluteUrlString($venue['logo_url'] ?? null);
        $coverUrl = $this->absoluteUrlString($venue['cover_url'] ?? null)
            ?? $this->absoluteUrlString($venue['hero_image_url'] ?? null);
        $publicDetailPath = $this->scalarString($venue['public_detail_path'] ?? null)
            ?? ($venueCanOpenPublicDetail ? '/parceiro/'.$venueSlug : null);

        return [
            'id' => $this->resolveLegacyDocumentId($venue),
            'display_name' => $venueDisplay ?? '',
            'slug' => $venueSlug,
            'can_open_public_detail' => $venueCanOpenPublicDetail,
            'public_detail_path' => $publicDetailPath,
            'profile_type' => $venueProfileType,
            'supports_public_navigation' => $supportsPublicNavigation,
            'tagline' => $this->scalarString($venue['tagline'] ?? null),
            'hero_image_url' => $coverUrl,
            'logo_url' => $avatarUrl,
            'avatar_url' => $avatarUrl,
            'cover_url' => $coverUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $venue
     * @return array<string, mixed>|null
     */
    private function formatVenueDetailPayload(array $venue): ?array
    {
        $payload = $this->formatVenuePreviewPayload($venue);
        if ($payload === null) {
            return null;
        }

        return [
            ...$payload,
            'bio' => $this->scalarString($venue['bio'] ?? null),
            'taxonomy_terms' => $this->ensureTaxonomySnapshots($venue['taxonomy_terms'] ?? []),
            'gallery_groups' => is_array($venue['gallery_groups'] ?? null)
                ? array_values($venue['gallery_groups'])
                : [],
        ];
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
                ? ($isAdminContext
                    ? $this->formatManagementEventList($eventsById->get($eventId), $occurrencesByEventId)
                    : $this->formatPublicEventList($eventsById->get($eventId), $occurrencesByEventId))
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
        $eventIds = $this->occurrenceNestedAccountStore->eventIdsForMemberProfiles([
            $relatedAccountProfileId,
        ]);

        if ($eventIds === []) {
            $query->whereRaw(['_id' => ['$in' => []]]);

            return;
        }

        $query->whereIn('_id', $this->buildEventIdCandidates($eventIds));
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
            return $this->formatPublicDetailPayload($event, $userId, null, $preloadedOccurrences);
        }

        $selectedOccurrenceId = (string) $selectedOccurrence->_id;
        $payload = $this->formatPublicDetailPayload($selectedOccurrence, $userId, $event, $preloadedOccurrences);
        $payload['event_id'] = (string) $event->_id;
        $payload['slug'] = $this->scalarString($event->slug ?? null) ?? $payload['slug'];
        $payload['thumb'] = $this->normalizeThumbPayload(
            $this->normalizeArray($event->thumb ?? null)
        );
        $payload['occurrences'] = $this->resolveEventOccurrences(
            $event,
            $selectedOccurrenceId,
            $preloadedOccurrences,
            true
        );
        $payload['profile_groups'] = $this->publicRelatedProfileGroupMetadata(
            $event,
            $preloadedOccurrences
        );

        return $this->withCanonicalHeroImage($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMetadataEvent(Event $event): array
    {
        $eventId = isset($event->_id) ? (string) $event->_id : '';
        $location = $this->normalizeArray($event->location ?? []);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray($event->thumb ?? null)
        );
        $occurrenceDocuments = $eventId === ''
            ? []
            : EventOccurrence::query()
                ->where('event_id', $eventId)
                ->orderBy('starts_at')
                ->get();
        $publicCounterparts = $this->publicCounterpartsForEventOccurrences(
            $occurrenceDocuments,
            $this->buildPublicCounterpartContext($occurrenceDocuments),
        );
        $hydrationContext = [
            'physical_hosts' => $this->resolveCurrentPhysicalHostsForReadDocuments(
                array_merge([$event], is_array($occurrenceDocuments) ? $occurrenceDocuments : $occurrenceDocuments->all()),
                false,
            ),
        ];
        $physicalHostsById = is_array($hydrationContext['physical_hosts'] ?? null)
            ? $hydrationContext['physical_hosts']
            : [];
        $venuePayload = $this->currentVenuePayloadFromResolvedHosts(
            $event->place_ref ?? null,
            $venue,
            $physicalHostsById,
        );

        return $this->withPublicCounterpartContract([
            'slug' => $this->scalarString($event->slug ?? null) ?? '',
            'title' => $this->scalarString($event->title ?? null) ?? '',
            'content' => $this->scalarString($event->content ?? null) ?? '',
            'location' => $location === [] ? null : $location,
            'place_ref' => $placeRef === [] ? null : $placeRef,
            'venue' => $venuePayload,
            'thumb' => $thumb,
        ], $publicCounterparts['profiles'], $publicCounterparts['counterpart_count']);
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
        $occurrenceDocuments = $preloadedOccurrences;
        if ($occurrenceDocuments === null && $eventId !== '') {
            $occurrenceDocuments = EventOccurrence::query()
                ->where('event_id', $eventId)
                ->orderBy('starts_at')
                ->get();
        }
        $this->occurrenceNestedAccountStore->materializeLegacyIfNeeded(
            $event,
            $event->trashed(),
        );
        $hydrationDocuments = [$event];
        foreach ($occurrenceDocuments ?? [] as $occurrence) {
            $hydrationDocuments[] = $occurrence;
        }
        $hydrationContext = [
            'related_profiles' => $this->resolveCurrentRelatedProfilesForReadDocuments(
                $hydrationDocuments,
                false,
            ),
            'physical_hosts' => $this->resolveCurrentPhysicalHostsForReadDocuments(
                $hydrationDocuments,
                false,
            ),
        ];
        $relatedProfilesById = is_array($hydrationContext['related_profiles'] ?? null)
            ? $hydrationContext['related_profiles']
            : [];
        $physicalHostsById = is_array($hydrationContext['physical_hosts'] ?? null)
            ? $hydrationContext['physical_hosts']
            : [];
        $type = $this->normalizeArray($event->type ?? null);
        $location = $this->normalizeArray($event->location ?? []);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray($event->thumb ?? null)
        );
        $managementCounterpart = $this->publicCounterpartsForEventOccurrences(
            $occurrenceDocuments ?? [],
            $this->buildManagementCounterpartContext($occurrenceDocuments ?? []),
        );
        $taxonomyTerms = $this->ensureTaxonomySnapshots(
            $event->taxonomy_terms ?? []
        );
        $typeVisual = $this->normalizeEventTypeVisual(
            $this->normalizeArray($type['visual'] ?? $type['poi_visual'] ?? null)
        );
        $publication = $event->publication ?? null;
        $publication = is_array($publication) ? $publication : (array) $publication;
        $venuePayload = $this->currentVenuePayloadFromResolvedHosts(
            $event->place_ref ?? null,
            $venue,
            $physicalHostsById,
        );
        $geo = $this->normalizeArray($location['geo'] ?? $event->geo_location ?? null);
        $coordinates = $geo['coordinates'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];
        }

        $resolvedOccurrences = $this->resolveEventOccurrences(
            $event,
            null,
            $occurrenceDocuments,
            false,
            $relatedProfilesById,
            $physicalHostsById,
        );
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

        return $this->withPublicCounterpartContract([
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
            'profile_groups' => $this->managementRootProfileGroupsFromOccurrences($occurrences),
            'capabilities' => $this->resolveEventCapabilities($event),
            'taxonomy_terms' => $taxonomyTerms,
            'publication' => [
                'status' => $this->scalarString($publication['status'] ?? null) ?? 'draft',
                'publish_at' => $this->formatDate($publication['publish_at'] ?? null),
            ],
            'created_at' => $event->created_at?->toJSON(),
            'updated_at' => $event->updated_at?->toJSON(),
            'deleted_at' => $event->deleted_at?->toJSON(),
        ], $managementCounterpart['profiles'], $managementCounterpart['counterpart_count']);
    }

    /**
     * @param  iterable<int, mixed>  $events
     * @return array<int, array<string, mixed>>
     */
    public function formatAgendaEvents(
        iterable $events,
        ?string $userId = null,
        bool $includeArtists = true
    ): array {
        $items = is_array($events) ? array_values($events) : iterator_to_array($events, false);
        $parentEventsById = $this->loadParentEventsForOccurrences($items);
        $occurrenceIds = array_values(array_filter(array_map(
            fn (mixed $event): string => $this->isOccurrencePayload($event)
                ? trim((string) ($event->_id ?? ''))
                : '',
            $items,
        )));
        $rootEventIds = array_values(array_filter(array_map(
            fn (mixed $event): string => $this->isOccurrencePayload($event) || ! isset($event->_id)
                ? ''
                : (string) $event->_id,
            $items,
        )));
        $publicEventOccurrencesByEventId = $this->loadOccurrencesByEventIds($rootEventIds);
        $publicOccurrenceDocuments = $this->loadOccurrencesByIds($occurrenceIds);
        foreach ($publicEventOccurrencesByEventId as $occurrences) {
            foreach ($occurrences as $occurrence) {
                if ($occurrence instanceof EventOccurrence) {
                    $publicOccurrenceDocuments[] = $occurrence;
                }
            }
        }
        $hydrationContext = [
            'physical_hosts' => $this->resolveCurrentPhysicalHostsForReadDocuments(
                array_merge($items, array_values($parentEventsById)),
                true,
            ),
            'public_counterparts' => $this->buildPublicCounterpartContext($publicOccurrenceDocuments),
            'public_event_occurrences' => $publicEventOccurrencesByEventId,
        ];

        return array_values(array_map(
            fn (mixed $event): array => $this->formatAgendaEvent(
                $event,
                $userId,
                $includeArtists,
                $this->resolveParentEventContext($event, $parentEventsById),
                $hydrationContext,
            ),
            $items
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAgendaEvent(
        mixed $event,
        ?string $userId = null,
        bool $includeArtists = true,
        ?Event $parentEvent = null,
        array $hydrationContext = [],
    ): array {
        $isOccurrence = $this->isOccurrencePayload($event);
        $physicalHostsById = is_array($hydrationContext['physical_hosts'] ?? null)
            ? $hydrationContext['physical_hosts']
            : [];
        $type = $this->normalizeArray($event->type ?? null);
        $location = $this->normalizeArray($event->location ?? []);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray(
                $parentEvent !== null && $isOccurrence
                    ? ($parentEvent->thumb ?? null)
                    : ($event->thumb ?? null)
            )
        );
        $publicCounterpartContext = is_array($hydrationContext['public_counterparts'] ?? null)
            ? $hydrationContext['public_counterparts']
            : ['summaries_by_occurrence' => [], 'profiles_by_id' => []];
        if ($isOccurrence) {
            $counterpart = $this->publicCounterpartsForOccurrenceId(
                trim((string) ($event->_id ?? '')),
                $publicCounterpartContext,
            );
        } else {
            $eventOccurrences = is_array($hydrationContext['public_event_occurrences'] ?? null)
                ? ($hydrationContext['public_event_occurrences'][(string) ($event->_id ?? '')] ?? [])
                : [];
            if ($eventOccurrences === []) {
                $eventOccurrences = $this->loadEventOccurrenceDocuments($event);
                $publicCounterpartContext = $this->buildPublicCounterpartContext($eventOccurrences);
            }
            $counterpart = $this->publicCounterpartsForEventOccurrences(
                $eventOccurrences,
                $publicCounterpartContext,
            );
        }
        $taxonomyTerms = $this->resolvePublicEventTaxonomyTerms($event);
        $typeVisual = $this->normalizeEventTypeVisual(
            $this->normalizeArray($type['visual'] ?? $type['poi_visual'] ?? null)
        );
        $venuePayload = $this->currentVenuePayloadFromResolvedHosts(
            $event->place_ref ?? null,
            $venue,
            $physicalHostsById,
        );
        $geo = $this->normalizeArray($location['geo'] ?? $event->geo_location ?? null);
        $coordinates = $geo['coordinates'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];
        }

        $eventId = $isOccurrence ? (string) $event->event_id : (isset($event->_id) ? (string) $event->_id : '');
        $occurrenceId = $isOccurrence && isset($event->_id) ? (string) $event->_id : null;

        $payload = [
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'slug' => $this->scalarString($event->slug ?? null)
                ?? ($parentEvent !== null ? $this->scalarString($parentEvent->slug ?? null) : null)
                ?? '',
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
            'location' => $location === [] ? null : $location,
            'place_ref' => $placeRef === [] ? null : $placeRef,
            'venue' => $venuePayload,
            'latitude' => $lat,
            'longitude' => $lng,
            'thumb' => $thumb,
            'date_time_start' => $isOccurrence
                ? $this->formatDate($this->extractRawAttribute($event, 'starts_at'))
                : $this->formatDate($this->extractRawAttribute($event, 'date_time_start')),
            'date_time_end' => $isOccurrence
                ? $this->formatDate($this->extractRawAttribute($event, 'ends_at'))
                : $this->formatDate($this->extractRawAttribute($event, 'date_time_end')),
            'taxonomy_terms' => $taxonomyTerms,
        ];

        return $this->withPublicCounterpartContract(
            $payload,
            $counterpart['profiles'],
            $counterpart['counterpart_count'],
        );
    }

    /**
     * @param  array<string, iterable<int, EventOccurrence>>|null  $occurrencesByEventId
     * @return array<string, mixed>
     */
    public function formatManagementEventList(
        Event $event,
        ?array $occurrencesByEventId = null,
        array $hydrationContext = [],
    ): array {
        return $this->formatEventListPayload($event, $occurrencesByEventId, false, $hydrationContext);
    }

    /**
     * @param  array<string, iterable<int, EventOccurrence>>|null  $occurrencesByEventId
     * @return array<string, mixed>
     */
    private function formatPublicEventList(
        Event $event,
        ?array $occurrencesByEventId = null,
        array $hydrationContext = [],
    ): array {
        return $this->formatEventListPayload($event, $occurrencesByEventId, true, $hydrationContext);
    }

    /**
     * @param  array<string, iterable<int, EventOccurrence>>|null  $occurrencesByEventId
     * @return array<string, mixed>
     */
    private function formatEventListPayload(
        Event $event,
        ?array $occurrencesByEventId = null,
        bool $includeTaxonomyTerms = false,
        array $hydrationContext = [],
    ): array {
        $eventId = isset($event->_id) ? (string) $event->_id : '';
        $preloadedOccurrences = $eventId !== '' && $occurrencesByEventId !== null
            ? ($occurrencesByEventId[$eventId] ?? [])
            : null;
        if ($hydrationContext === []) {
            $hydrationDocuments = [$event];
            foreach ($preloadedOccurrences ?? [] as $occurrence) {
                $hydrationDocuments[] = $occurrence;
            }
            $hydrationContext = [
                'related_profiles' => $includeTaxonomyTerms
                    ? []
                    : $this->resolveCurrentRelatedProfilesForReadDocuments(
                        $hydrationDocuments,
                        false,
                    ),
                'physical_hosts' => $this->resolveCurrentPhysicalHostsForReadDocuments(
                    $hydrationDocuments,
                    $includeTaxonomyTerms,
                ),
                'public_counterparts' => $includeTaxonomyTerms
                    ? $this->buildPublicCounterpartContext($preloadedOccurrences ?? [])
                    : null,
            ];
        }
        $relatedProfilesById = is_array($hydrationContext['related_profiles'] ?? null)
            ? $hydrationContext['related_profiles']
            : [];
        $physicalHostsById = is_array($hydrationContext['physical_hosts'] ?? null)
            ? $hydrationContext['physical_hosts']
            : [];
        $type = $this->normalizeArray($event->type ?? null);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray($event->thumb ?? null)
        );
        $eventOccurrences = $preloadedOccurrences ?? $this->loadEventOccurrenceDocuments($event);
        if ($includeTaxonomyTerms) {
            $publicCounterpartContext = is_array($hydrationContext['public_counterparts'] ?? null)
                ? $hydrationContext['public_counterparts']
                : $this->buildPublicCounterpartContext($eventOccurrences);
            $counterpart = $this->publicCounterpartsForEventOccurrences(
                $eventOccurrences,
                $publicCounterpartContext,
            );
        } else {
            $counterpart = $this->publicCounterpartsForEventOccurrences(
                $eventOccurrences,
                $this->buildManagementCounterpartContext($eventOccurrences),
            );
        }
        $typeVisual = $this->normalizeEventTypeVisual(
            $this->normalizeArray($type['visual'] ?? $type['poi_visual'] ?? null)
        );
        $publication = $event->publication ?? null;
        $publication = is_array($publication) ? $publication : (array) $publication;
        $venuePayload = $this->currentVenuePayloadFromResolvedHosts(
            $event->place_ref ?? null,
            $venue,
            $physicalHostsById,
        );
        $occurrences = $this->resolveManagementListOccurrences($event, $preloadedOccurrences);
        $dateTimeStart = $this->formatDate($this->extractRawAttribute($event, 'date_time_start'))
            ?? ($occurrences[0]['date_time_start'] ?? null);
        $dateTimeEnd = $this->formatDate($this->extractRawAttribute($event, 'date_time_end'))
            ?? ($occurrences[0]['date_time_end'] ?? null);

        $payload = [
            'event_id' => $eventId,
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
            'place_ref' => $placeRef === [] ? null : $placeRef,
            'venue' => $venuePayload,
            'thumb' => $thumb,
            'date_time_start' => $dateTimeStart,
            'date_time_end' => $dateTimeEnd,
            'occurrences' => $occurrences,
            'publication' => [
                'status' => $this->scalarString($publication['status'] ?? null) ?? 'draft',
                'publish_at' => $this->formatDate($publication['publish_at'] ?? null),
            ],
            'created_at' => $event->created_at?->toJSON(),
            'updated_at' => $event->updated_at?->toJSON(),
            'deleted_at' => $event->deleted_at?->toJSON(),
        ];

        if ($includeTaxonomyTerms) {
            $payload['taxonomy_terms'] = $this->resolvePublicEventTaxonomyTerms($event);

            return $this->withPublicCounterpartContract(
                $payload,
                $counterpart['profiles'],
                $counterpart['counterpart_count'],
            );
        }

        return $this->withPublicCounterpartContract(
            $payload,
            $counterpart['profiles'],
            $counterpart['counterpart_count'],
        );
    }

    public function eventBelongsToAccount(Event $event, string $accountId): bool
    {
        return $this->eventOwnedByAccount($event, $accountId);
    }

    public function eventEditableByAccount(Event $event, string $accountId, ?string $actorUserId = null): bool
    {
        $normalizedAccountId = trim($accountId);
        if ($normalizedAccountId === '') {
            return false;
        }

        return $this->eventOwnedByAccount($event, $normalizedAccountId);
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
            'taxonomy' => $this->normalizeTaxonomyArray($queryParams['taxonomy'] ?? []),
            'occurrence_ids' => $this->normalizeStringArray($queryParams['occurrence_ids'] ?? []),
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
        $confirmedOccurrenceIds = $this->resolveConfirmedOccurrenceIds($filters, $userId);
        if (is_array($confirmedOccurrenceIds) && $confirmedOccurrenceIds === []) {
            return [
                'page_rows' => [],
                'discovery_filter_facets' => $this->emptyAgendaDiscoveryFilterFacetsPayload(),
            ];
        }

        $pipeline = $this->buildAgendaPipeline($filters, $skip, $limit, $useGeo, $confirmedOccurrenceIds);
        EventBus::dispatch('belluga.events.public_agenda_aggregate', [
            'purpose' => 'public_agenda_page_with_runtime_facets',
            'pipeline' => $pipeline,
        ]);

        /** @var Collection<int, EventOccurrence> $events */
        app(TenantRequestLifecycleTrace::class)->record('endpoint.agenda.aggregate.start');
        $events = EventOccurrence::raw(fn ($collection) => $collection->aggregate($pipeline));
        app(TenantRequestLifecycleTrace::class)->record('endpoint.agenda.aggregate.complete');
        $payload = $this->normalizeArray($events->first());

        return [
            'page_rows' => $this->hydrateAgendaAggregateRows($payload['page_rows'] ?? []),
            'discovery_filter_facets' => $this->formatAgendaDiscoveryFilterFacets(
                $payload,
                $filters
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, mixed>
     */
    private function runStreamQuery(array $filters, ?string $userId, Carbon $since, bool $useGeo): array
    {
        $confirmedOccurrenceIds = $this->resolveConfirmedOccurrenceIds($filters, $userId);
        if (is_array($confirmedOccurrenceIds) && $confirmedOccurrenceIds === []) {
            return [];
        }

        $pipeline = $this->buildStreamPipeline($filters, $since, $useGeo, $confirmedOccurrenceIds);

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
        ?array $confirmedOccurrenceIds = null
    ): array {
        $now = new UTCDateTime(Carbon::now());
        $pipeline = [];

        $baseMatch = [
            'deleted_at' => null,
            'is_event_published' => true,
        ];
        $baseMatch = $this->combineMatchExpressions(
            $baseMatch,
            $this->buildOccurrenceIdsMatch($filters['occurrence_ids'])
        );

        if ($useGeo && $filters['origin_lat'] !== null && $filters['origin_lng'] !== null) {
            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $filters['origin_lng'], (float) $filters['origin_lat']],
                ],
                'key' => 'geo_location',
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => $baseMatch,
            ];

            if ($filters['max_distance_meters'] !== null) {
                $geoNear['maxDistance'] = (float) $filters['max_distance_meters'];
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        } else {
            $pipeline[] = ['$match' => $baseMatch];
        }

        $this->applyConfirmedOccurrencesFilter($pipeline, $confirmedOccurrenceIds);
        $this->appendPublicParentEventLookupStages($pipeline);

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
        $this->applySearchFilter($pipeline, $filters['search'] ?? null, $useGeo);

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

        $taxonomyGroups = array_keys($this->groupTaxonomySelectionsByType($filters['taxonomy']));
        $pipeline[] = [
            '$facet' => $this->buildAgendaFacetBranches(
                filters: $filters,
                taxonomyGroups: $taxonomyGroups,
                sort: $sort,
                skip: $skip,
                limit: $limit,
            ),
        ];

        return $pipeline;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $taxonomyGroups
     * @param  array<string, int>  $sort
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildAgendaFacetBranches(
        array $filters,
        array $taxonomyGroups,
        array $sort,
        int $skip,
        int $limit,
    ): array {
        $branches = [];

        $pageRows = [];
        $this->applyCategoryFilter($pageRows, $filters['categories']);
        $this->applyTaxonomyFilter($pageRows, $filters['taxonomy'], 'taxonomy_terms');
        $pageRows[] = ['$sort' => $sort];
        $pageRows[] = ['$skip' => $skip];
        $pageRows[] = ['$limit' => $limit];
        $branches['page_rows'] = $pageRows;

        $typeKeys = [];
        $this->applyTaxonomyFilter($typeKeys, $filters['taxonomy'], 'taxonomy_terms');
        $typeKeys[] = [
            '$project' => [
                'filter_keys' => [
                    '$setUnion' => [
                        [['$ifNull' => ['$type.slug', null]]],
                        ['$ifNull' => ['$categories', []]],
                    ],
                ],
            ],
        ];
        $typeKeys[] = ['$unwind' => '$filter_keys'];
        $typeKeys[] = ['$match' => ['filter_keys' => ['$ne' => null]]];
        $typeKeys[] = ['$group' => ['_id' => '$filter_keys']];
        $typeKeys[] = [
            '$project' => [
                '_id' => 0,
                'filter_key' => '$_id',
            ],
        ];
        $typeKeys[] = ['$sort' => ['filter_key' => 1]];
        $branches['type_keys'] = $typeKeys;

        $taxonomyBase = [];
        $this->applyCategoryFilter($taxonomyBase, $filters['categories']);
        $this->applyTaxonomyFilter($taxonomyBase, $filters['taxonomy'], 'taxonomy_terms');
        $this->appendTaxonomyTermsGroupStages($taxonomyBase, 'taxonomy_terms');
        $branches['taxonomy_base'] = $taxonomyBase;

        foreach ($taxonomyGroups as $taxonomyGroup) {
            $groupFilters = [];
            $this->applyCategoryFilter($groupFilters, $filters['categories']);
            $this->applyTaxonomyFilter(
                $groupFilters,
                $this->excludeTaxonomySelectionsForType(
                    $filters['taxonomy'],
                    $taxonomyGroup
                ),
                'taxonomy_terms'
            );
            $this->appendTaxonomyTermsGroupStages($groupFilters, 'taxonomy_terms');
            $branches[$this->taxonomyFacetBranchKey($taxonomyGroup)] = $groupFilters;
        }

        return $branches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     */
    private function appendTaxonomyTermsGroupStages(
        array &$pipeline,
        string $field = 'taxonomy_terms'
    ): void {
        $termField = '$'.$field;
        $pipeline[] = ['$unwind' => $termField];
        $pipeline[] = [
            '$group' => [
                '_id' => [
                    'type' => $termField.'.type',
                    'value' => $termField.'.value',
                ],
                'label' => [
                    '$first' => [
                        '$ifNull' => [
                            $termField.'.label',
                            $termField.'.name',
                        ],
                    ],
                ],
                'group_label' => [
                    '$first' => [
                        '$ifNull' => [
                            $termField.'.taxonomy_name',
                            $termField.'.type',
                        ],
                    ],
                ],
            ],
        ];
        $pipeline[] = [
            '$project' => [
                '_id' => 0,
                'type' => '$_id.type',
                'value' => '$_id.value',
                'label' => '$label',
                'group_label' => '$group_label',
            ],
        ];
        $pipeline[] = ['$sort' => ['type' => 1, 'label' => 1, 'value' => 1]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildStreamPipeline(
        array $filters,
        Carbon $since,
        bool $useGeo,
        ?array $confirmedOccurrenceIds = null
    ): array {
        $sinceUtc = new UTCDateTime($since);
        $pipeline = [];

        $baseMatch = [
            '$or' => [
                ['updated_at' => ['$gt' => $sinceUtc]],
                ['deleted_at' => ['$gt' => $sinceUtc]],
            ],
        ];
        $baseMatch = $this->combineMatchExpressions(
            $baseMatch,
            $this->buildOccurrenceIdsMatch($filters['occurrence_ids'])
        );

        if ($useGeo && $filters['origin_lat'] !== null && $filters['origin_lng'] !== null) {
            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $filters['origin_lng'], (float) $filters['origin_lat']],
                ],
                'key' => 'geo_location',
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => $baseMatch,
            ];

            if ($filters['max_distance_meters'] !== null) {
                $geoNear['maxDistance'] = (float) $filters['max_distance_meters'];
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        } else {
            $pipeline[] = ['$match' => $baseMatch];
        }

        $this->applySearchFilter($pipeline, $filters['search'] ?? null, $useGeo);
        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy'], 'taxonomy_terms');
        $this->applyConfirmedOccurrencesFilter($pipeline, $confirmedOccurrenceIds);
        $this->appendPublicParentEventLookupStages($pipeline);

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
     * @param  array<int, array{type: string, value: string}>  $taxonomy
     */
    private function applyTaxonomyFilter(
        array &$pipeline,
        array $taxonomy,
        string $field = 'taxonomy_terms'
    ): void {
        if ($taxonomy === []) {
            return;
        }

        $groupedSelections = $this->groupTaxonomySelectionsByType($taxonomy);
        if ($groupedSelections === []) {
            return;
        }

        $groupMatches = [];
        foreach ($groupedSelections as $type => $values) {
            $valueMatches = [];
            foreach ($values as $value) {
                $valueMatches[] = [
                    $field => [
                        '$elemMatch' => [
                            'type' => $type,
                            'value' => $value,
                        ],
                    ],
                ];
            }
            if ($valueMatches !== []) {
                $groupMatches[] = count($valueMatches) === 1
                    ? $valueMatches[0]
                    : ['$or' => $valueMatches];
            }
        }

        if ($groupMatches !== []) {
            $pipeline[] = [
                '$match' => count($groupMatches) === 1
                    ? $groupMatches[0]
                    : ['$and' => $groupMatches],
            ];
        }
    }

    /**
     * @param  array<int, array{type: string, value: string}>  $taxonomy
     * @return array<string, array<int, string>>
     */
    private function groupTaxonomySelectionsByType(array $taxonomy): array
    {
        $grouped = [];
        foreach ($taxonomy as $term) {
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            $grouped[$type] ??= [];
            $grouped[$type][] = $value;
        }

        foreach ($grouped as $type => $values) {
            $grouped[$type] = array_values(array_unique($values));
        }

        return $grouped;
    }

    /**
     * @param  array<string, array<int, string>>  $taxonomy
     * @return array<int, array{type: string, value: string}>
     */
    private function flattenTaxonomySelectionMap(array $taxonomy): array
    {
        $flattened = [];
        foreach ($taxonomy as $type => $values) {
            $normalizedType = trim((string) $type);
            if ($normalizedType === '') {
                continue;
            }
            foreach ($values as $value) {
                $normalizedValue = trim((string) $value);
                if ($normalizedValue === '') {
                    continue;
                }
                $flattened[] = [
                    'type' => $normalizedType,
                    'value' => $normalizedValue,
                ];
            }
        }

        return $flattened;
    }

    /**
     * @param  array<int, array{type: string, value: string}>  $taxonomy
     * @return array<int, array{type: string, value: string}>
     */
    private function excludeTaxonomySelectionsForType(array $taxonomy, string $excludedType): array
    {
        return array_values(array_filter(
            $taxonomy,
            static fn (array $term): bool => trim((string) ($term['type'] ?? '')) !== $excludedType
        ));
    }

    private function taxonomyFacetBranchKey(string $taxonomyType): string
    {
        return 'taxonomy_scope_'.trim($taxonomyType);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     */
    private function appendPublicParentEventLookupStages(array &$pipeline): void
    {
        $pipeline[] = [
            '$addFields' => [
                'event_object_id' => [
                    '$convert' => [
                        'input' => '$event_id',
                        'to' => 'objectId',
                        'onError' => null,
                        'onNull' => null,
                    ],
                ],
            ],
        ];
        $pipeline[] = [
            '$lookup' => [
                'from' => 'events',
                'localField' => 'event_object_id',
                'foreignField' => '_id',
                'as' => 'event',
            ],
        ];
        $pipeline[] = ['$unwind' => '$event'];
        $pipeline[] = ['$match' => ['event.deleted_at' => null]];
        $pipeline[] = ['$project' => ['event' => 0, 'event_object_id' => 0]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function formatAgendaDiscoveryFilterFacets(array $payload, array $filters): array
    {
        $filterKeys = [];
        foreach ($this->normalizeArray($payload['type_keys'] ?? []) as $row) {
            $normalized = $this->normalizeArray($row);
            $value = strtolower(trim((string) (
                $normalized['filter_key']
                ?? $normalized['_id']
                ?? $normalized['id']
                ?? ''
            )));
            if ($value === '') {
                continue;
            }
            $filterKeys[$value] = $value;
        }

        $taxonomyOptions = $this->formatMergedTaxonomyFacetRows(
            baseRows: $this->normalizeArray($payload['taxonomy_base'] ?? []),
            scopedRowsByType: $this->agendaScopedTaxonomyRowsByType($payload, $filters),
        );

        return [
            'surface' => 'home.events',
            'filter_keys' => array_values($filterKeys),
            'taxonomy_options' => $taxonomyOptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $filters
     * @return array<string, array<int, mixed>>
     */
    private function agendaScopedTaxonomyRowsByType(array $payload, array $filters): array
    {
        $rows = [];
        foreach (array_keys($this->groupTaxonomySelectionsByType($filters['taxonomy'] ?? [])) as $taxonomyType) {
            $rows[$taxonomyType] = $this->normalizeArray(
                $payload[$this->taxonomyFacetBranchKey($taxonomyType)] ?? []
            );
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $baseRows
     * @param  array<string, array<int, mixed>>  $scopedRowsByType
     * @return array<string, array<string, mixed>>
     */
    private function formatMergedTaxonomyFacetRows(array $baseRows, array $scopedRowsByType): array
    {
        $mergedRowsByType = [];

        foreach ($baseRows as $row) {
            $normalized = $this->normalizeArray($row);
            $type = strtolower(trim((string) ($normalized['type'] ?? '')));
            if ($type === '') {
                continue;
            }
            $mergedRowsByType[$type][] = $normalized;
        }

        foreach ($scopedRowsByType as $type => $rows) {
            $mergedRowsByType[$type] = array_map(
                fn (mixed $row): array => $this->normalizeArray($row),
                $rows
            );
        }

        $taxonomyOptions = [];
        foreach ($mergedRowsByType as $type => $rows) {
            $terms = [];
            $groupLabel = $type;
            foreach ($rows as $row) {
                $value = strtolower(trim((string) ($row['value'] ?? '')));
                $label = trim((string) ($row['label'] ?? $value));
                if ($value === '' || $label === '') {
                    continue;
                }
                $groupLabel = trim((string) ($row['group_label'] ?? $groupLabel));
                $terms[$value] = [
                    'value' => $value,
                    'label' => $label,
                ];
            }

            if ($terms === []) {
                continue;
            }

            uasort($terms, static fn (array $left, array $right): int => [$left['label'], $left['value']] <=> [$right['label'], $right['value']]);
            $taxonomyOptions[$type] = [
                'key' => $type,
                'label' => $groupLabel === '' ? $type : $groupLabel,
                'terms' => array_values($terms),
                'terms_truncated' => false,
                'terms_limit' => count($terms),
            ];
        }

        ksort($taxonomyOptions);

        return $taxonomyOptions;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAgendaDiscoveryFilterFacetsPayload(): array
    {
        return [
            'surface' => 'home.events',
            'filter_keys' => [],
            'taxonomy_options' => [],
        ];
    }

    /**
     * @return array<int, Fluent>
     */
    private function hydrateAgendaAggregateRows(mixed $rows): array
    {
        $hydrated = [];
        foreach ($this->normalizeArray($rows) as $row) {
            $payload = $this->normalizeArray($row);
            if ($payload === []) {
                continue;
            }
            if (! array_key_exists('_id', $payload) && array_key_exists('id', $payload)) {
                $payload['_id'] = $payload['id'];
            }
            if (! array_key_exists('id', $payload) && array_key_exists('_id', $payload)) {
                $payload['id'] = $payload['_id'];
            }
            $hydrated[] = new Fluent($payload);
        }

        return $hydrated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param  array<int, string>|null  $confirmedOccurrenceIds
     */
    private function applyConfirmedOccurrencesFilter(array &$pipeline, ?array $confirmedOccurrenceIds): void
    {
        if ($confirmedOccurrenceIds === null) {
            return;
        }

        $pipeline[] = [
            '$match' => [
                '_id' => ['$in' => $this->buildDocumentIdCandidates($confirmedOccurrenceIds)],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $occurrenceIds
     * @return array<string, mixed>
     */
    private function buildOccurrenceIdsMatch(array $occurrenceIds): array
    {
        if ($occurrenceIds === []) {
            return [];
        }

        return [
            '_id' => ['$in' => $this->buildDocumentIdCandidates($occurrenceIds)],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $expressions
     * @return array<string, mixed>
     */
    private function combineMatchExpressions(array ...$expressions): array
    {
        $filtered = array_values(array_filter(
            $expressions,
            static fn (array $expression): bool => $expression !== []
        ));

        if ($filtered === []) {
            return [];
        }

        if (count($filtered) === 1) {
            return $filtered[0];
        }

        return ['$and' => $filtered];
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
                ['categories' => ['$regex' => $regex, '$options' => 'i']],
                ['taxonomy_terms.value' => ['$regex' => $regex, '$options' => 'i']],
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
    private function resolveConfirmedOccurrenceIds(array $filters, ?string $userId): ?array
    {
        if ((bool) ($filters['confirmed_only'] ?? false) !== true) {
            return null;
        }

        if (! is_string($userId) || trim($userId) === '') {
            return [];
        }

        return array_values(array_unique(array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $this->eventAttendanceRead->listConfirmedOccurrenceIdsForUser($userId)),
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
     * @param  iterable<int, mixed>  $events
     * @return array<int, array<string, mixed>>
     */
    private function formatPublicDetailPayload(
        mixed $event,
        ?string $userId = null,
        ?Event $parentEvent = null,
        ?iterable $preloadedOccurrences = null,
    ): array {
        $isOccurrence = $this->isOccurrencePayload($event);
        $type = $this->normalizeArray($event->type ?? null);
        $location = $this->normalizeArray($event->location ?? []);
        $placeRef = $this->normalizePlaceRefPayload(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeThumbPayload(
            $this->normalizeArray(
                $parentEvent !== null && $isOccurrence
                    ? ($parentEvent->thumb ?? null)
                    : ($event->thumb ?? null)
            )
        );
        $taxonomyTerms = $this->resolvePublicEventTaxonomyTerms($event);

        $geo = $this->normalizeArray($location['geo'] ?? $event->geo_location ?? null);
        $coordinates = $geo['coordinates'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];
        }

        $eventRoot = $parentEvent !== null && $isOccurrence ? $parentEvent : $event;
        $detailOccurrences = $preloadedOccurrences ?? $this->loadEventOccurrenceDocuments($eventRoot);
        $hydrationDocuments = [$eventRoot];
        if ($event !== $eventRoot) {
            $hydrationDocuments[] = $event;
        }
        foreach ($detailOccurrences as $occurrence) {
            $hydrationDocuments[] = $occurrence;
        }
        $programmingRelatedProfilesById = $this->resolveCurrentRelatedProfilesForProgrammingDocuments(
            $hydrationDocuments,
            true,
        );
        $physicalHostsById = $this->resolveCurrentPhysicalHostsForReadDocuments(
            $hydrationDocuments,
            true,
        );
        $publicCounterpartContext = $this->buildPublicCounterpartContext($detailOccurrences);
        $counterpart = $isOccurrence && $event instanceof EventOccurrence
            ? $this->publicCounterpartsForOccurrence($event, $publicCounterpartContext)
            : $this->publicCounterpartsForEventOccurrences(
                $detailOccurrences,
                $publicCounterpartContext,
            );
        $venuePayload = $this->currentVenuePayloadFromResolvedHosts(
            $event->place_ref ?? null,
            $venue,
            $physicalHostsById,
            true,
        );
        $occurrences = $this->resolveEventOccurrences(
            $eventRoot,
            null,
            $detailOccurrences,
            true,
            $programmingRelatedProfilesById,
            $physicalHostsById,
        );
        $capabilities = $this->resolveEventCapabilities($event);
        $createdBy = $this->normalizeArray($event->created_by ?? []);
        $eventId = $isOccurrence ? (string) $event->event_id : (isset($event->_id) ? (string) $event->_id : '');
        $profileGroups = $this->publicRelatedProfileGroupMetadata(
            $eventRoot,
            $detailOccurrences,
        );
        $typeVisual = $this->normalizeEventTypeVisual(
            $this->normalizeArray($type['visual'] ?? $type['poi_visual'] ?? null)
        );

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
            'profile_groups' => $profileGroups,
            'programming_items' => $this->normalizeProgrammingItems(
                $event->programming_items ?? [],
                $programmingRelatedProfilesById,
                $physicalHostsById,
                true,
            ),
            'capabilities' => $capabilities,
            'taxonomy_terms' => $taxonomyTerms,
        ];

        return $this->withPublicCounterpartContract(
            $payload,
            $counterpart['profiles'],
            $counterpart['counterpart_count'],
        );
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    private function publicRelatedProfileGroupMetadata(Event $event, iterable $occurrences): array
    {
        $eventRouteKey = trim((string) ($event->slug ?? ''));
        if ($eventRouteKey === '') {
            $eventRouteKey = trim((string) $event->getKey());
        }

        return $this->occurrenceNestedAccountStore->mergedPublicGroupMetadata(
            $event,
            $occurrences,
            $eventRouteKey,
        );
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array{summaries_by_occurrence: array<string, array{first_profile_id: ?string, counterpart_count: int}>, profiles_by_id: array<string, array<string, mixed>>}
     */
    private function buildPublicCounterpartContext(iterable $occurrences): array
    {
        $summaries = $this->occurrenceNestedAccountStore
            ->publicCounterpartSummariesByOccurrence($occurrences);
        $candidateIds = array_values(array_unique(array_filter(array_map(
            static fn (array $summary): string => trim((string) ($summary['first_profile_id'] ?? '')),
            $summaries,
        ), static fn (string $profileId): bool => $profileId !== '')));

        return [
            'summaries_by_occurrence' => $summaries,
            'profiles_by_id' => $this->resolveCurrentRelatedProfilesByIds($candidateIds, true),
        ];
    }

    /**
     * @return array{summaries_by_occurrence: array<string, array{first_profile_id: ?string, counterpart_count: int}>, profiles_by_id: array<string, array<string, mixed>>}
     */
    private function buildManagementCounterpartContext(iterable $occurrences): array
    {
        $summaries = $this->occurrenceNestedAccountStore
            ->publicCounterpartSummariesByOccurrence($occurrences);
        $candidateIds = array_values(array_unique(array_filter(array_map(
            static fn (array $summary): string => trim((string) ($summary['first_profile_id'] ?? '')),
            $summaries,
        ), static fn (string $profileId): bool => $profileId !== '')));

        return [
            'summaries_by_occurrence' => $summaries,
            'profiles_by_id' => $this->resolveCurrentRelatedProfilesByIds($candidateIds, false),
        ];
    }

    /**
     * @param  array{summaries_by_occurrence?: array<string, array{first_profile_id: ?string, counterpart_count: int}>, profiles_by_id?: array<string, array<string, mixed>>}  $context
     * @return array{profiles: array<int, array<string, mixed>>, counterpart_count: int}
     */
    private function publicCounterpartsForOccurrence(EventOccurrence $occurrence, array $context): array
    {
        return $this->publicCounterpartsForOccurrenceId(
            trim((string) $occurrence->getKey()),
            $context,
        );
    }

    /**
     * @param  array{summaries_by_occurrence?: array<string, array{first_profile_id: ?string, counterpart_count: int}>, profiles_by_id?: array<string, array<string, mixed>>}  $context
     * @return array{profiles: array<int, array<string, mixed>>, counterpart_count: int}
     */
    private function publicCounterpartsForOccurrenceId(string $occurrenceId, array $context): array
    {
        $summaries = is_array($context['summaries_by_occurrence'] ?? null)
            ? $context['summaries_by_occurrence']
            : [];
        $summary = is_array($summaries[$occurrenceId] ?? null) ? $summaries[$occurrenceId] : [];
        $profileId = trim((string) ($summary['first_profile_id'] ?? ''));
        $profilesById = is_array($context['profiles_by_id'] ?? null)
            ? $context['profiles_by_id']
            : [];
        $profile = $profileId === '' ? null : ($profilesById[$profileId] ?? null);

        return [
            'profiles' => is_array($profile) ? [$profile] : [],
            'counterpart_count' => max(0, (int) ($summary['counterpart_count'] ?? 0)),
        ];
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @param  array{summaries_by_occurrence?: array<string, array{first_profile_id: ?string, counterpart_count: int}>, profiles_by_id?: array<string, array<string, mixed>>}  $context
     * @return array{profiles: array<int, array<string, mixed>>, counterpart_count: int}
     */
    private function publicCounterpartsForEventOccurrences(iterable $occurrences, array $context): array
    {
        foreach ($occurrences as $occurrence) {
            if (! $occurrence instanceof EventOccurrence) {
                continue;
            }

            // Root Event public surfaces mirror only the first ordered occurrence.
            return $this->publicCounterpartsForOccurrence($occurrence, $context);
        }

        return [
            'profiles' => [],
            'counterpart_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $linkedAccountProfiles
     * @return array<string, mixed>
     */
    private function withPublicCounterpartContract(
        array $payload,
        array $linkedAccountProfiles,
        ?int $counterpartCount = null,
    ): array
    {
        $counterpartPreview = $this->normalizeManagementLinkedAccountProfiles(
            $linkedAccountProfiles,
        );
        $payload['counterpart_preview'] = $counterpartPreview;
        $payload['counterpart_count'] = $counterpartCount ?? count($counterpartPreview);
        $payload = $this->withCanonicalHeroImage($payload);
        $payload['counterpart_preview'] = array_slice($counterpartPreview, 0, 1);

        return $payload;
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    public function relatedProfileTabMembers(
        Event $event,
        string $tabId,
        ?string $cursor = null,
        ?int $perPage = null,
    ): array {
        $occurrences = $this->loadEventOccurrenceDocuments($event);

        return $this->occurrenceNestedAccountStore->publicMemberPage(
            $event,
            $occurrences,
            $tabId,
            InputConstraints::PUBLIC_PAGE_SIZE_MAX,
            $perPage,
            $cursor,
        );
    }

    /**
     * @return array{data: array<int, array<string, mixed>>,next_cursor:?string}
     */
    public function adminOccurrenceGroupMembers(
        Event $event,
        EventOccurrence $occurrence,
        string $groupId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
    ): array {
        $this->occurrenceNestedAccountStore->materializeLegacyIfNeeded(
            $event,
            $event->trashed(),
        );

        return $this->occurrenceNestedAccountStore->adminOccurrenceMemberPage(
            $occurrence,
            $groupId,
            $defaultPerPage,
            $suppliedPerPage,
            $cursor,
        );
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
        if ($value instanceof Arrayable) {
            return $value->toArray();
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

    /**
     * @return array<int, array<string, string>>
     */
    private function resolvePublicEventTaxonomyTerms(mixed $event): array
    {
        return $this->ensureTaxonomySnapshots($event->taxonomy_terms ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     */
    private function applySearchFilter(array &$pipeline, mixed $search, bool $useGeo): void
    {
        if (! is_string($search) || trim($search) === '' || $useGeo) {
            return;
        }

        $pipeline[] = [
            '$match' => $this->buildSearchMatchExpression($search),
        ];
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
     * @param  array<int, string>  $occurrenceIds
     * @return array<int, EventOccurrence>
     */
    private function loadOccurrencesByIds(array $occurrenceIds): array
    {
        $normalized = array_values(array_filter(array_unique(array_map(
            static fn (mixed $occurrenceId): string => trim((string) $occurrenceId),
            $occurrenceIds
        ))));

        if ($normalized === []) {
            return [];
        }

        return EventOccurrence::query()
            ->whereIn('_id', $this->buildDocumentIdCandidates($normalized))
            ->orderBy('starts_at')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<string, Event>
     */
    private function loadParentEventsForOccurrences(array $events): array
    {
        $eventIds = array_values(array_filter(array_unique(array_map(
            fn (mixed $event): string => $this->parentEventIdForOccurrence($event) ?? '',
            $events
        ))));

        if ($eventIds === []) {
            return [];
        }

        return Event::query()
            ->whereIn('_id', $this->buildEventIdCandidates($eventIds))
            ->get()
            ->keyBy(static fn (Event $event): string => isset($event->_id) ? (string) $event->_id : '')
            ->all();
    }

    /**
     * @param  array<string, Event>  $parentEventsById
     */
    private function resolveParentEventContext(mixed $event, array $parentEventsById): ?Event
    {
        $eventId = $this->parentEventIdForOccurrence($event);
        if ($eventId === null) {
            return null;
        }

        return $parentEventsById[$eventId] ?? null;
    }

    private function isOccurrencePayload(mixed $event): bool
    {
        return $this->parentEventIdForOccurrence($event) !== null;
    }

    private function parentEventIdForOccurrence(mixed $event): ?string
    {
        $eventId = trim((string) ($event->event_id ?? ''));

        return $eventId === '' ? null : $eventId;
    }

    /**
     * @param  array<int, string>  $eventIds
     * @return array<int, string|ObjectId>
     */
    private function buildEventIdCandidates(array $eventIds): array
    {
        return $this->buildDocumentIdCandidates($eventIds);
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

    private function resolveSelectedOccurrence(
        Event $event,
        ?string $occurrenceRef,
        ?iterable $preloadedOccurrences = null
    ): ?EventOccurrence {
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

    private function loadEventOccurrenceDocuments(mixed $event): mixed
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
        ?iterable $preloadedOccurrences = null,
        bool $forPublic = false,
        array $relatedProfilesById = [],
        array $physicalHostsById = [],
    ): array {
        if (isset($event->event_id) && (string) $event->event_id !== '') {
            $start = $this->formatDate($this->extractRawAttribute($event, 'starts_at'));
            if ($start === null) {
                return [];
            }

            $occurrenceId = isset($event->_id) ? (string) $event->_id : null;
            $programmingItems = $this->normalizeProgrammingItems(
                $event->programming_items ?? [],
                $relatedProfilesById,
                $physicalHostsById,
                $forPublic,
            );
            $payload = [
                'occurrence_id' => $occurrenceId,
                'occurrence_slug' => isset($event->occurrence_slug) ? (string) $event->occurrence_slug : null,
                'date_time_start' => $start,
                'date_time_end' => $this->formatDate($this->extractRawAttribute($event, 'ends_at')),
                'is_selected' => true,
                'has_location_override' => false,
                'location_override' => null,
                'own_taxonomy_terms' => $this->ensureTaxonomySnapshots($event->own_taxonomy_terms ?? []),
                'taxonomy_terms' => $this->ensureTaxonomySnapshots($event->taxonomy_terms ?? []),
                'programming_items' => $programmingItems,
                'programming_count' => count($programmingItems),
            ];

            if (! $forPublic) {
                $payload['profile_groups'] = $occurrenceId === null
                    ? []
                    : $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                        $event,
                        (string) ($event->event_id ?? ''),
                    );
            }

            return [$payload];
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
                return $documents->map(function (EventOccurrence $occurrence) use (
                    $eventId,
                    $selectedOccurrenceId,
                    $forPublic,
                    $relatedProfilesById,
                    $physicalHostsById
                ): array {
                    $occurrenceId = isset($occurrence->_id) ? (string) $occurrence->_id : null;
                    $programmingItems = $this->normalizeProgrammingItems(
                        $occurrence->programming_items ?? [],
                        $relatedProfilesById,
                        $physicalHostsById,
                        $forPublic,
                    );
                    $payload = [
                        'occurrence_id' => $occurrenceId,
                        'occurrence_slug' => isset($occurrence->occurrence_slug) ? (string) $occurrence->occurrence_slug : null,
                        'date_time_start' => $this->formatDate($this->extractRawAttribute($occurrence, 'starts_at')),
                        'date_time_end' => $this->formatDate($this->extractRawAttribute($occurrence, 'ends_at')),
                        'is_selected' => $selectedOccurrenceId !== null && $occurrenceId === $selectedOccurrenceId,
                        'has_location_override' => false,
                        'location_override' => null,
                        'own_taxonomy_terms' => $this->ensureTaxonomySnapshots($occurrence->own_taxonomy_terms ?? []),
                        'taxonomy_terms' => $this->ensureTaxonomySnapshots($occurrence->taxonomy_terms ?? []),
                        'programming_items' => $programmingItems,
                        'programming_count' => count($programmingItems),
                    ];

                    if (! $forPublic) {
                        $payload['profile_groups'] = $occurrenceId === null
                            ? []
                            : $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                                $occurrence,
                                $eventId,
                            );
                    }

                    return $payload;
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

    private function applyAccountFiltersToQuery($query, string $accountId): void
    {
        $normalizedAccountId = trim($accountId);
        if ($normalizedAccountId === '') {
            return;
        }

        $this->applyAccountOwnershipFilter(
            $query,
            $this->resolveAccountProfileIds($normalizedAccountId),
            $this->resolveAccountUserIds($normalizedAccountId),
        );
    }

    private function eventOwnedByAccount(Event $event, string $accountId): bool
    {
        $normalizedAccountId = trim($accountId);
        if ($normalizedAccountId === '') {
            return false;
        }

        $placeRefId = $this->resolvePlaceRefId(
            $this->normalizeArray($event->place_ref ?? null)
        );
        $profileIds = $this->resolveAccountProfileIds($normalizedAccountId);
        if ($placeRefId !== '') {
            return $profileIds !== [] && in_array($placeRefId, $profileIds, true);
        }

        return $this->eventCreatedByAccountUserIds(
            $event,
            $this->resolveAccountUserIds($normalizedAccountId),
        );
    }

    private function eventCreatedByAccountUserIds(Event $event, array $accountUserIds): bool
    {
        if ($accountUserIds === []) {
            return false;
        }

        $createdBy = $this->normalizeArray($event->created_by ?? []);
        $createdByType = trim((string) ($createdBy['type'] ?? ''));
        $createdById = trim((string) ($createdBy['id'] ?? ''));

        return $createdByType === 'account_user'
            && $createdById !== ''
            && in_array($createdById, $accountUserIds, true);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAccountUserIds(string $accountId): array
    {
        $normalizedAccountId = trim($accountId);
        if ($normalizedAccountId === '') {
            return [];
        }

        return $this->eventAccountResolver->resolveAccessibleAccountUserIds($normalizedAccountId);
    }

    /**
     * @param  array<int, string>  $profileIds
     * @param  array<int, string>  $accountUserIds
     */
    private function applyAccountOwnershipFilter($query, array $profileIds, array $accountUserIds): void
    {
        $profileCandidates = $this->buildProfileIdCandidatesFromList($profileIds);
        $accountUserCandidates = $this->buildDocumentIdCandidates($accountUserIds);

        if ($profileCandidates === [] && $accountUserCandidates === []) {
            $query->whereRaw(['_id' => ['$in' => []]]);

            return;
        }

        $clauses = [];

        if ($profileCandidates !== []) {
            $clauses[] = [
                '$or' => [
                    ['place_ref.id' => ['$in' => $profileCandidates]],
                    ['place_ref._id' => ['$in' => $profileCandidates]],
                ],
            ];
        }

        if ($accountUserCandidates !== []) {
            $clauses[] = [
                '$and' => [
                    $this->missingPlaceRefOwnershipClause(),
                    ['created_by.type' => 'account_user'],
                    [
                        '$or' => [
                            ['created_by.id' => ['$in' => $accountUserCandidates]],
                            ['created_by._id' => ['$in' => $accountUserCandidates]],
                        ],
                    ],
                ],
            ];
        }

        $query->whereRaw(count($clauses) === 1 ? $clauses[0] : ['$or' => $clauses]);
    }

    /**
     * When a concrete place_ref exists, account ownership is derived from that profile.
     * created_by is only the fallback for account-scoped online events that have no place_ref.
     *
     * @return array<string, mixed>
     */
    private function missingPlaceRefOwnershipClause(): array
    {
        return [
            '$and' => [
                [
                    '$or' => [
                        ['place_ref.id' => ['$exists' => false]],
                        ['place_ref.id' => null],
                        ['place_ref.id' => ''],
                    ],
                ],
                [
                    '$or' => [
                        ['place_ref._id' => ['$exists' => false]],
                        ['place_ref._id' => null],
                        ['place_ref._id' => ''],
                    ],
                ],
            ],
        ];
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

    private function resolveManagementListOccurrences(
        mixed $event,
        ?iterable $preloadedOccurrences = null
    ): array {
        if (isset($event->event_id) && (string) $event->event_id !== '') {
            $start = $this->formatDate($this->extractRawAttribute($event, 'starts_at'));
            if ($start === null) {
                return [];
            }

            return [[
                'occurrence_id' => isset($event->_id) ? (string) $event->_id : null,
                'occurrence_slug' => isset($event->occurrence_slug) ? (string) $event->occurrence_slug : null,
                'date_time_start' => $start,
                'date_time_end' => $this->formatDate($this->extractRawAttribute($event, 'ends_at')),
            ]];
        }

        $eventId = isset($event->_id) ? (string) $event->_id : '';
        if ($eventId === '') {
            return [];
        }

        $documents = $preloadedOccurrences === null
            ? EventOccurrence::query()
                ->where('event_id', $eventId)
                ->orderBy('starts_at')
                ->get()
            : collect($preloadedOccurrences);

        if ($documents->isEmpty()) {
            return [];
        }

        return $documents->map(function (EventOccurrence $occurrence): array {
            return [
                'occurrence_id' => isset($occurrence->_id) ? (string) $occurrence->_id : null,
                'occurrence_slug' => isset($occurrence->occurrence_slug) ? (string) $occurrence->occurrence_slug : null,
                'date_time_start' => $this->formatDate($this->extractRawAttribute($occurrence, 'starts_at')),
                'date_time_end' => $this->formatDate($this->extractRawAttribute($occurrence, 'ends_at')),
            ];
        })->filter(static fn (array $occurrence): bool => $occurrence['date_time_start'] !== null)
            ->values()
            ->all();
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    private function managementRootProfileGroupsFromOccurrences(array $occurrences): array
    {
        if (count($occurrences) !== 1) {
            return [];
        }

        $profileGroups = $occurrences[0]['profile_groups'] ?? null;

        return is_array($profileGroups) ? array_values($profileGroups) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  ...$groupSets
     * @return array<int, array<string, mixed>>
     */
    private function mergeProfileGroupsForPublic(array ...$groupSets): array
    {
        $merged = [];
        $indexById = [];
        $indexByLabel = [];

        foreach ($groupSets as $groupSet) {
            foreach ($groupSet as $group) {
                $id = trim((string) ($group['id'] ?? ''));
                $label = trim((string) ($group['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $normalizedLabel = mb_strtolower($label);

                $profiles = $this->normalizeLinkedAccountProfileSummaries($group['profiles'] ?? []);
                if ($profiles === []) {
                    continue;
                }

                $groupIndex = $id !== '' ? ($indexById[$id] ?? null) : null;
                if ($groupIndex === null) {
                    $groupIndex = $indexByLabel[$normalizedLabel] ?? null;
                }
                $seenProfileIds = [];
                if ($groupIndex !== null) {
                    foreach ($merged[$groupIndex]['profiles'] as $existingProfile) {
                        $existingProfileId = trim((string) ($this->scalarString($existingProfile['id'] ?? null) ?? ''));
                        if ($existingProfileId !== '') {
                            $seenProfileIds[$existingProfileId] = true;
                        }
                    }
                }

                $profilesToAppend = [];
                foreach ($profiles as $profile) {
                    $profileId = trim((string) ($this->scalarString($profile['id'] ?? null) ?? ''));
                    if ($profileId === '' || isset($seenProfileIds[$profileId])) {
                        continue;
                    }

                    $profilesToAppend[] = $profile;
                    $seenProfileIds[$profileId] = true;
                }

                if ($profilesToAppend === []) {
                    continue;
                }

                if ($groupIndex === null) {
                    $groupIndex = count($merged);
                    if ($id !== '') {
                        $indexById[$id] = $groupIndex;
                    }
                    $indexByLabel[$normalizedLabel] = $groupIndex;
                    $merged[] = [
                        'id' => $id === '' ? Str::slug($label) : $id,
                        'label' => $label,
                        'order' => count($merged),
                        'profiles' => [],
                    ];
                } else {
                    if ($id !== '' && ! isset($indexById[$id])) {
                        $indexById[$id] = $groupIndex;
                    }
                    if (! isset($indexByLabel[$normalizedLabel])) {
                        $indexByLabel[$normalizedLabel] = $groupIndex;
                    }
                }

                foreach ($profilesToAppend as $profile) {
                    $merged[$groupIndex]['profiles'][] = $profile;
                }
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, array<string, mixed>>  $linkedProfiles
     * @return array<int, array<string, mixed>>
     */
    private function hydratePublicProfileGroupsFromLinkedProfiles(array $groups, array $linkedProfiles): array
    {
        if ($groups === [] || $linkedProfiles === []) {
            return $groups;
        }

        $profilesById = [];
        foreach ($this->normalizeLinkedAccountProfileSummaries($linkedProfiles) as $profile) {
            $profileId = trim((string) ($this->scalarString($profile['id'] ?? null) ?? ''));
            if ($profileId === '' || isset($profilesById[$profileId])) {
                continue;
            }

            $profilesById[$profileId] = $profile;
        }

        if ($profilesById === []) {
            return $groups;
        }

        $hydrated = [];
        foreach ($groups as $group) {
            $profiles = [];
            foreach ($this->normalizeLinkedAccountProfileSummaries($group['profiles'] ?? []) as $profile) {
                $profileId = trim((string) ($this->scalarString($profile['id'] ?? null) ?? ''));
                $aggregateProfile = $profileId === '' ? null : ($profilesById[$profileId] ?? null);
                $profiles[] = is_array($aggregateProfile)
                    ? $this->mergePublicLinkedAccountProfileSummaries($profile, $aggregateProfile)
                    : $profile;
            }

            $group['profiles'] = $profiles;
            $hydrated[] = $group;
        }

        return $hydrated;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function mergePublicLinkedAccountProfileSummaries(array $primary, array $fallback): array
    {
        $merged = $primary;

        $fallbackScalarFields = [
            'display_name',
            'slug',
            'profile_type',
            'party_type',
        ];

        foreach ($fallbackScalarFields as $field) {
            $value = $this->scalarString($merged[$field] ?? null);
            if ($value === null || $value === '') {
                $merged[$field] = $fallback[$field] ?? null;
            }
        }

        $avatarUrl = $this->scalarString($merged['avatar_url'] ?? null);
        if ($avatarUrl === null || $avatarUrl === '') {
            $merged['avatar_url'] = $fallback['avatar_url'] ?? null;
        }

        $coverUrl = $this->scalarString($merged['cover_url'] ?? null);
        if ($coverUrl === null || $coverUrl === '') {
            $merged['cover_url'] = $fallback['cover_url'] ?? null;
        }

        if ((bool) ($merged['can_open_public_detail'] ?? false) === false
            && (bool) ($fallback['can_open_public_detail'] ?? false) === true) {
            $merged['can_open_public_detail'] = true;
        }

        $publicDetailPath = $this->scalarString($merged['public_detail_path'] ?? null);
        if ($publicDetailPath === null || $publicDetailPath === '') {
            $merged['public_detail_path'] = $fallback['public_detail_path'] ?? null;
        }

        $taxonomyTerms = $this->normalizeArray($merged['taxonomy_terms'] ?? []);
        if ($taxonomyTerms === []) {
            $merged['taxonomy_terms'] = $fallback['taxonomy_terms'] ?? [];
        }

        $normalized = $this->normalizeLinkedAccountProfileSummary($merged);
        if ($normalized === null) {
            return $fallback;
        }

        $profileId = trim((string) ($this->scalarString($normalized['id'] ?? null) ?? ''));
        $normalized['avatar_url'] = $this->accountProfileMediaUrlString(
            $merged['avatar_url'] ?? null,
            $profileId,
            'avatar'
        );
        $normalized['cover_url'] = $this->accountProfileMediaUrlString(
            $merged['cover_url'] ?? null,
            $profileId,
            'cover'
        );
        $normalized['taxonomy_terms'] = $this->ensureTaxonomySnapshots($merged['taxonomy_terms'] ?? []);

        return $normalized;
    }

    /**
     * @param  iterable<int, mixed>  $documents
     * @return array<string, array<string, mixed>>
     */
    private function resolveCurrentRelatedProfilesForReadDocuments(
        iterable $documents,
        bool $publicOnly,
    ): array {
        $relatedProfileIds = [];

        foreach (is_array($documents) ? $documents : iterator_to_array($documents, false) as $document) {
            $this->collectRelatedProfileIdsForReadPayload($document, $relatedProfileIds);
        }

        return $this->resolveCurrentRelatedProfilesByIds(array_keys($relatedProfileIds), $publicOnly);
    }

    /**
     * @param  iterable<int, mixed>  $documents
     * @return array<string, array<string, mixed>>
     */
    private function resolveCurrentRelatedProfilesForProgrammingDocuments(
        iterable $documents,
        bool $publicOnly,
    ): array {
        $relatedProfileIds = [];

        foreach (is_array($documents) ? $documents : iterator_to_array($documents, false) as $document) {
            $this->collectRelatedProfileIdsForProgrammingPayload($document, $relatedProfileIds);
        }

        return $this->resolveCurrentRelatedProfilesByIds(array_keys($relatedProfileIds), $publicOnly);
    }

    /**
     * @param  iterable<int, mixed>  $documents
     * @return array<string, array{venue: array<string, mixed>, location: array<string, mixed>}>
     */
    private function resolveCurrentPhysicalHostsForReadDocuments(
        iterable $documents,
        bool $publicOnly,
    ): array {
        $physicalHostIds = [];

        foreach (is_array($documents) ? $documents : iterator_to_array($documents, false) as $document) {
            $this->collectPhysicalHostIdsForReadPayload($document, $physicalHostIds);
        }

        return $this->resolveCurrentPhysicalHostsByIds(array_keys($physicalHostIds), $publicOnly);
    }

    /**
     * @param  array<string, bool>  $relatedProfileIds
     */
    private function collectRelatedProfileIdsForReadPayload(
        mixed $payload,
        array &$relatedProfileIds,
    ): void {
        $this->appendOrderedIdentifiers(
            $relatedProfileIds,
            $this->profileIdsFromStoredProfileGroups(data_get($payload, 'profile_groups'))
        );
        $this->appendOrderedIdentifiers(
            $relatedProfileIds,
            $this->profileIdsFromStoredProfileGroups(data_get($payload, 'own_profile_groups'))
        );
        $this->collectRelatedProfileIdsForProgrammingPayload($payload, $relatedProfileIds);
    }

    /**
     * @param  array<string, bool>  $relatedProfileIds
     */
    private function collectRelatedProfileIdsForProgrammingPayload(
        mixed $payload,
        array &$relatedProfileIds,
    ): void {
        foreach ($this->normalizeProgrammingItemDrafts(data_get($payload, 'programming_items')) as $item) {
            $this->appendOrderedIdentifiers($relatedProfileIds, $item['account_profile_ids']);
        }
    }

    /**
     * @param  array<string, bool>  $physicalHostIds
     */
    private function collectPhysicalHostIdsForReadPayload(
        mixed $payload,
        array &$physicalHostIds,
    ): void {
        $placeRefPayload = $this->normalizeArray(data_get($payload, 'place_ref'));
        $placeRefId = $this->placeRefTargetsAccountProfile($placeRefPayload)
            ? $this->resolvePlaceRefId($placeRefPayload)
            : '';
        if ($placeRefId === '') {
            $placeRefId = $this->resolveLegacyDocumentId(
                $this->normalizeArray(data_get($payload, 'venue'))
            );
        }
        if ($placeRefId !== '') {
            $physicalHostIds[$placeRefId] = true;
        }

        foreach ($this->normalizeProgrammingItemDrafts(data_get($payload, 'programming_items')) as $item) {
            $programmingPlaceRefId = $item['place_ref_id'];
            if ($programmingPlaceRefId !== null && $programmingPlaceRefId !== '') {
                $physicalHostIds[$programmingPlaceRefId] = true;
            }
        }
    }

    /**
     * @param  array<string, bool>  $seen
     * @param  array<int, string>  $ids
     */
    private function appendOrderedIdentifiers(array &$seen, array $ids): void
    {
        foreach ($ids as $id) {
            $normalized = trim($id);
            if ($normalized !== '' && ! isset($seen[$normalized])) {
                $seen[$normalized] = true;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function profileIdsFromEventPartiesPayload(mixed $eventParties): array
    {
        $ids = [];

        foreach ($this->normalizeEventParties($eventParties) as $party) {
            $partyType = trim((string) ($party['party_type'] ?? ''));
            $profileId = trim((string) ($party['party_ref_id'] ?? ''));
            if ($profileId === '' || $partyType === 'venue' || in_array($profileId, $ids, true)) {
                continue;
            }

            $ids[] = $profileId;
        }

        return $ids;
    }

    /**
     * @return array<int, string>
     */
    private function profileIdsFromLinkedProfilePayloads(mixed $profiles): array
    {
        $ids = [];

        foreach ($this->normalizeLinkedAccountProfileSummaries($profiles) as $profile) {
            $profileId = trim((string) ($this->scalarString($profile['id'] ?? null) ?? ''));
            if ($profileId !== '' && ! in_array($profileId, $ids, true)) {
                $ids[] = $profileId;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, array{
     *   sequence: int,
     *   time: ?string,
     *   end_time: ?string,
     *   title: ?string,
     *   account_profile_ids: array<int, string>,
     *   place_ref: array<string, mixed>|null,
     *   place_ref_id: ?string,
     *   fallback_location_profile: array<string, mixed>|null
     * }>
     */
    private function normalizeProgrammingItemDrafts(mixed $items): array
    {
        $rows = $this->normalizeArray($items);
        if ($rows === []) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $index => $row) {
            $item = $this->normalizeArray($row);
            if ($item === []) {
                continue;
            }

            $profileIds = array_values(array_unique(array_filter(array_map(
                static fn (mixed $profileId): string => trim((string) $profileId),
                $this->normalizeArray($item['account_profile_ids'] ?? [])
            ), static fn (string $profileId): bool => $profileId !== '')));
            if ($profileIds === []) {
                $profileIds = $this->profileIdsFromLinkedProfilePayloads($item['linked_account_profiles'] ?? []);
            }

            $placeRef = $this->normalizeNullableArray($item['place_ref'] ?? null);
            $placeRefId = $placeRef === null ? '' : $this->resolvePlaceRefId($placeRef);
            if ($placeRefId === '') {
                $placeRefId = trim((string) ($this->scalarString(data_get($item, 'location_profile.id')) ?? ''));
            }

            $fallbackLocationProfile = $this->normalizeNullableArray($item['location_profile'] ?? null);

            $normalized[] = [
                'sequence' => isset($item['sequence']) && is_numeric($item['sequence'])
                    ? (int) $item['sequence']
                    : (int) $index,
                'time' => $this->scalarString($item['time'] ?? null),
                'end_time' => $this->scalarString($item['end_time'] ?? null),
                'title' => $this->scalarString($item['title'] ?? null),
                'account_profile_ids' => $profileIds,
                'place_ref' => $placeRef,
                'place_ref_id' => $placeRefId === '' ? null : $placeRefId,
                'fallback_location_profile' => $fallbackLocationProfile,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    private function resolveCurrentRelatedProfilesByIds(array $profileIds, bool $publicOnly): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds
        ), static fn (string $profileId): bool => $profileId !== '')));
        if ($ids === []) {
            return [];
        }

        return $publicOnly
            ? $this->eventProfileResolver->resolveExistingEventPartyDisplayProfilesByIds($ids)
            : $this->eventProfileResolver->resolveExistingEventPartyProfilesByIds($ids);
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array{venue: array<string, mixed>, location: array<string, mixed>}>
     */
    private function resolveCurrentPhysicalHostsByIds(array $profileIds, bool $publicOnly): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds
        ), static fn (string $profileId): bool => $profileId !== '')));
        if ($ids === []) {
            return [];
        }

        return $publicOnly
            ? $this->eventProfileResolver->resolveExistingPublicPhysicalHostsByProfileIds($ids)
            : $this->eventProfileResolver->resolveExistingPhysicalHostsByProfileIds($ids);
    }

    /**
     * @param  array<int, string>  $orderedProfileIds
     * @param  array<string, array<string, mixed>>  $profilesById
     * @return array<int, array<string, mixed>>
     */
    private function orderedCurrentRelatedProfiles(array $orderedProfileIds, array $profilesById): array
    {
        $profiles = [];
        $seenIds = [];

        foreach ($orderedProfileIds as $profileId) {
            $normalizedId = trim($profileId);
            if ($normalizedId === '' || isset($seenIds[$normalizedId])) {
                continue;
            }

            $profile = $profilesById[$normalizedId] ?? null;
            if (! is_array($profile)) {
                continue;
            }

            $normalized = $this->normalizeManagementLinkedAccountProfileSummary($profile);
            if ($normalized === null) {
                continue;
            }

            $profiles[] = $normalized;
            $seenIds[$normalizedId] = true;
        }

        return $profiles;
    }

    /**
     * @param  array<string, array{venue: array<string, mixed>, location: array<string, mixed>}>  $physicalHostsById
     */
    private function currentVenuePayloadFromResolvedHosts(
        mixed $placeRef,
        mixed $fallbackVenue,
        array $physicalHostsById,
        bool $detail = false,
    ): ?array {
        $placeRefPayload = $this->normalizeArray($placeRef);
        $profileId = $this->placeRefTargetsAccountProfile($placeRefPayload)
            ? $this->resolvePlaceRefId($placeRefPayload)
            : '';

        if ($profileId !== '') {
            $resolvedHost = $physicalHostsById[$profileId] ?? null;
            if (! is_array($resolvedHost)) {
                return null;
            }

            $venue = $this->normalizeArray($resolvedHost['venue'] ?? []);

            return $detail
                ? $this->formatVenueDetailPayload($venue)
                : $this->formatVenuePreviewPayload($venue);
        }

        $legacyVenue = $this->normalizeArray($fallbackVenue);
        if ($legacyVenue === []) {
            return null;
        }

        return $detail
            ? $this->formatVenueDetailPayload($legacyVenue)
            : $this->formatVenuePreviewPayload($legacyVenue);
    }

    /**
     * Legacy `place_ref.type=venue` rows are compatibility residue only.
     * Live host lookup is valid only when the canonical account-profile target
     * is explicitly present.
     *
     * @param  array<string, mixed>  $placeRef
     */
    private function placeRefTargetsAccountProfile(array $placeRef): bool
    {
        return trim((string) ($placeRef['type'] ?? '')) === 'account_profile';
    }

    /**
     * @param  array<string, array{venue: array<string, mixed>, location: array<string, mixed>}>  $physicalHostsById
     */
    private function currentProgrammingLocationProfileFromResolvedHosts(
        ?string $placeRefId,
        ?array $fallbackLocationProfile,
        array $physicalHostsById,
    ): ?array {
        $normalizedPlaceRefId = trim((string) ($placeRefId ?? ''));
        if ($normalizedPlaceRefId !== '') {
            $resolvedHost = $physicalHostsById[$normalizedPlaceRefId] ?? null;
            if (! is_array($resolvedHost)) {
                return null;
            }

            $venuePayload = $this->formatVenuePreviewPayload(
                $this->normalizeArray($resolvedHost['venue'] ?? [])
            );
            if ($venuePayload === null) {
                return null;
            }

            $location = $this->normalizeArray($resolvedHost['location'] ?? []);
            if ($location !== []) {
                $venuePayload['location'] = $location;
            }

            return $venuePayload;
        }

        return $fallbackLocationProfile === null
            ? null
            : $this->normalizeManagementLinkedAccountProfileSummary($fallbackLocationProfile);
    }

    /**
     * @param  iterable<int, EventOccurrence>|null  $occurrenceDocuments
     * @return array<int, string>
     */
    private function orderedRelatedProfileIdsForEvent(
        Event $event,
        ?iterable $occurrenceDocuments = null,
    ): array {
        $orderedIds = [];
        $this->appendOrderedIdentifiers(
            $orderedIds,
            $this->profileIdsFromStoredProfileGroups($event->profile_groups ?? [])
        );

        $eventId = isset($event->_id) ? (string) $event->_id : '';
        $documents = $occurrenceDocuments;
        if ($documents === null && $eventId !== '') {
            $documents = EventOccurrence::query()
                ->where('event_id', $eventId)
                ->orderBy('starts_at')
                ->get();
        }

        foreach ($documents ?? [] as $occurrence) {
            if (! $occurrence instanceof EventOccurrence) {
                continue;
            }

            $this->appendOrderedIdentifiers(
                $orderedIds,
                $this->occurrenceOwnedRelatedProfileIds($occurrence)
            );
        }

        return array_keys($orderedIds);
    }

    /**
     * @return array<int, string>
     */
    private function orderedRelatedProfileIdsForOccurrencePayload(mixed $event): array
    {
        return $this->effectiveOccurrenceRelatedProfileIds($event);
    }

    /**
     * @return array<int, string>
     */
    private function occurrenceOwnedRelatedProfileIds(mixed $event): array
    {
        $orderedIds = [];

        $this->appendOrderedIdentifiers(
            $orderedIds,
            $this->profileIdsFromStoredProfileGroups(data_get($event, 'own_profile_groups'))
        );
        return array_keys($orderedIds);
    }

    /**
     * Agenda/detail occurrence rows may expose only the already-effective
     * occurrence projection instead of `own_*` fields. In that case the
     * canonical fallback is the occurrence row itself, never the event-wide
     * aggregate across sibling occurrences.
     *
     * @return array<int, string>
     */
    private function effectiveOccurrenceRelatedProfileIds(mixed $event): array
    {
        $ownedIds = $this->occurrenceOwnedRelatedProfileIds($event);
        if ($ownedIds !== []) {
            return $ownedIds;
        }

        $orderedIds = [];
        $this->appendOrderedIdentifiers(
            $orderedIds,
            $this->profileIdsFromStoredProfileGroups(data_get($event, 'profile_groups'))
        );
        return array_keys($orderedIds);
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function canonicalLinkedAccountProfilesForEvent(
        Event $event,
        ?iterable $occurrenceDocuments = null,
        bool $publicOnly = false,
        ?array $resolvedProfilesById = null,
    ): array {
        $orderedIds = $this->orderedRelatedProfileIdsForEvent($event, $occurrenceDocuments);
        if ($orderedIds === []) {
            return [];
        }

        $profilesById = $resolvedProfilesById
            ?? $this->resolveCurrentRelatedProfilesByIds($orderedIds, $publicOnly);

        return $this->orderedCurrentRelatedProfiles($orderedIds, $profilesById);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function canonicalLinkedAccountProfilesForOccurrencePayload(
        mixed $event,
        bool $publicOnly = false,
        ?array $resolvedProfilesById = null,
        bool $ownOnly = false,
    ): array {
        $orderedIds = $ownOnly
            ? $this->occurrenceOwnedRelatedProfileIds($event)
            : $this->orderedRelatedProfileIdsForOccurrencePayload($event);

        if ($orderedIds === []) {
            return [];
        }

        $profilesById = $resolvedProfilesById
            ?? $this->resolveCurrentRelatedProfilesByIds($orderedIds, $publicOnly);

        return $this->orderedCurrentRelatedProfiles($orderedIds, $profilesById);
    }

    /**
     * @return array<int, string>
     */
    private function profileIdsFromStoredProfileGroups(mixed $profileGroups): array
    {
        $ids = [];
        foreach ($this->normalizeArray($profileGroups) as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($this->normalizeArray($group['account_profile_ids'] ?? $group['profile_ids'] ?? []) as $rawProfileId) {
                $profileId = trim((string) $rawProfileId);
                if ($profileId !== '' && ! in_array($profileId, $ids, true)) {
                    $ids[] = $profileId;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeManagementLinkedAccountProfiles(mixed $linkedProfiles): array
    {
        $normalizedById = [];
        foreach ($this->normalizeLinkedAccountProfileSummaries($linkedProfiles) as $profile) {
            $profileId = trim((string) ($this->scalarString($profile['id'] ?? null) ?? ''));
            $profilePayload = $this->normalizeManagementLinkedAccountProfileSummary(
                $profile
            );
            if ($profilePayload === null) {
                continue;
            }

            $profileId = trim((string) ($this->scalarString($profilePayload['id'] ?? null) ?? ''));
            if ($profileId === '' || isset($normalizedById[$profileId])) {
                continue;
            }

            $normalizedById[$profileId] = $profilePayload;
        }

        return array_values($normalizedById);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeManagementLinkedAccountProfileSummary(mixed $profile): ?array
    {
        $normalized = $this->normalizeLinkedAccountProfileSummary($profile);
        if ($normalized === null) {
            return null;
        }

        $profileId = trim((string) ($this->scalarString($normalized['id'] ?? null) ?? ''));
        $normalized['avatar_url'] = $this->accountProfileMediaUrlString(
            $normalized['avatar_url'] ?? null,
            $profileId,
            'avatar'
        );
        $normalized['cover_url'] = $this->accountProfileMediaUrlString(
            $normalized['cover_url'] ?? null,
            $profileId,
            'cover'
        );

        return $normalized;
    }

    /**
     * @return array<int, array{id: string, label: string, order: int, account_profile_ids: array<int, string>}>
     */
    private function normalizeProfileGroups(
        mixed $rawGroups,
        string $ownerType = 'event',
        string $ownerId = '',
    ): array {
        return $this->profileGroupMemberStore->inflateGroupsWithMembers(
            $rawGroups,
            $ownerType,
            $ownerId,
        );
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
    private function normalizeProgrammingItems(
        mixed $items,
        array $relatedProfilesById = [],
        array $physicalHostsById = [],
        bool $publicOnly = false,
    ): array {
        $drafts = $this->normalizeProgrammingItemDrafts($items);
        if ($drafts === []) {
            return [];
        }

        if ($relatedProfilesById === []) {
            $profileIds = [];
            foreach ($drafts as $draft) {
                foreach ($draft['account_profile_ids'] as $profileId) {
                    if ($profileId !== '' && ! in_array($profileId, $profileIds, true)) {
                        $profileIds[] = $profileId;
                    }
                }
            }

            $relatedProfilesById = $this->resolveCurrentRelatedProfilesByIds($profileIds, $publicOnly);
        }

        if ($physicalHostsById === []) {
            $placeRefIds = [];
            foreach ($drafts as $draft) {
                $placeRefId = trim((string) ($draft['place_ref_id'] ?? ''));
                if ($placeRefId !== '' && ! in_array($placeRefId, $placeRefIds, true)) {
                    $placeRefIds[] = $placeRefId;
                }
            }

            $physicalHostsById = $this->resolveCurrentPhysicalHostsByIds($placeRefIds, $publicOnly);
        }

        $normalized = [];
        foreach ($drafts as $draft) {
            $normalized[] = [
                'sequence' => $draft['sequence'],
                'time' => $draft['time'],
                'end_time' => $draft['end_time'],
                'title' => $draft['title'],
                'account_profile_ids' => $draft['account_profile_ids'],
                'linked_account_profiles' => $this->orderedCurrentRelatedProfiles(
                    $draft['account_profile_ids'],
                    $relatedProfilesById
                ),
                'place_ref' => $draft['place_ref'],
                'location_profile' => $this->currentProgrammingLocationProfileFromResolvedHosts(
                    $draft['place_ref_id'],
                    $draft['fallback_location_profile'],
                    $physicalHostsById,
                ),
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['sequence'], $left['time'] ?? '', $left['title'] ?? '']
                <=> [$right['sequence'], $right['time'] ?? '', $right['title'] ?? '']
        );

        return array_values(array_map(static function (array $item): array {
            unset($item['sequence']);

            return $item;
        }, $normalized));
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

        $id = trim((string) ($this->scalarString($payload['id'] ?? null) ?? ''));
        $displayName = trim((string) ($this->scalarString($payload['display_name'] ?? $payload['name'] ?? null) ?? ''));
        $profileType = trim((string) ($this->scalarString($payload['profile_type'] ?? $payload['party_type'] ?? null) ?? ''));
        if ($id === '' || $displayName === '' || $profileType === '') {
            return null;
        }
        if (! $this->eventProfileResolver->isProfileTypeQueryable($profileType)) {
            return null;
        }

        $slug = trim((string) ($this->scalarString($payload['slug'] ?? null) ?? ''));
        $canOpenPublicDetail = array_key_exists('can_open_public_detail', $payload)
            ? (bool) ($payload['can_open_public_detail'] ?? false)
            : ($slug !== ''
                && $this->eventProfileResolver->isProfileTypePubliclyNavigable($profileType));
        $publicDetailPath = $this->scalarString($payload['public_detail_path'] ?? null)
            ?? ($canOpenPublicDetail ? '/parceiro/'.$slug : null);

        $payload['id'] = $id;
        $payload['display_name'] = $displayName;
        $payload['profile_type'] = $profileType;
        $payload['slug'] = $slug === '' ? null : $slug;
        $payload['can_open_public_detail'] = $canOpenPublicDetail;
        $payload['public_detail_path'] = $publicDetailPath;

        return $payload;
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withCanonicalHeroImage(array $payload): array
    {
        $payload['hero_image_url'] = $this->eventHeroImages->resolveFromPayload($payload);

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

    private function accountProfileMediaUrlString(mixed $value, ?string $profileId, string $kind): ?string
    {
        $absolute = $this->absoluteUrlString($value);
        if ($absolute !== null) {
            return $absolute;
        }

        $normalized = $this->scalarString($value);
        $resolvedProfileId = trim((string) ($profileId ?? ''));
        $resolvedKind = trim($kind);
        if ($normalized === null || $resolvedProfileId === '' || $resolvedKind === '') {
            return null;
        }

        $baseUrl = $this->currentRequestBaseUrl();
        if ($baseUrl === null) {
            return null;
        }

        $path = parse_url($normalized, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalizedPath = '/'.ltrim(trim($path), '/');
        $canonicalPath = "/api/v1/media/account-profiles/{$resolvedProfileId}/{$resolvedKind}";
        $legacyPath = "/account-profiles/{$resolvedProfileId}/{$resolvedKind}";
        if ($normalizedPath !== $canonicalPath && $normalizedPath !== $legacyPath) {
            return null;
        }

        $query = parse_url($normalized, PHP_URL_QUERY);

        return $baseUrl.$canonicalPath.(is_string($query) && trim($query) !== '' ? '?'.$query : '');
    }

    private function currentRequestBaseUrl(): ?string
    {
        $baseUrl = trim((string) request()->getSchemeAndHttpHost());

        return $baseUrl === '' ? null : rtrim($baseUrl, '/');
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
