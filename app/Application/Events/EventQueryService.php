<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\Event;
use App\Models\Tenants\TenantSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Eloquent\Collection;

class EventQueryService
{
    private const DEFAULT_RADIUS_MIN_KM = 1.0;
    private const DEFAULT_RADIUS_KM = 5.0;
    private const DEFAULT_RADIUS_MAX_KM = 50.0;
    private const DEFAULT_PAGE_SIZE = 10;
    private const DEFAULT_EVENT_DURATION_MS = 10800000; // 3h

    /**
     * @param array<string, mixed> $queryParams
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function fetchAgenda(array $queryParams, ?AccountUser $user): array
    {
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $pageSize = (int) ($queryParams['page_size'] ?? $queryParams['per_page'] ?? self::DEFAULT_PAGE_SIZE);
        $pageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
        $skip = ($page - 1) * $pageSize;
        $limit = $pageSize + 1;

        $filters = $this->normalizeFilters($queryParams);
        $raw = $this->runAgendaQuery($filters, $user, $skip, $limit, true);

        if ($filters['use_geo'] && $raw === []) {
            $raw = $this->runAgendaQuery($filters, $user, $skip, $limit, false);
        }

        $hasMore = count($raw) > $pageSize;
        $pageSlice = array_slice($raw, 0, $pageSize);

        return [
            'items' => array_map(fn ($event) => $this->formatEvent($event, $user), $pageSlice),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function paginateManagement(
        array $queryParams,
        bool $includeArchived,
        int $perPage,
        bool $isAdminContext,
        ?Account $accountContext = null,
        ?string $accountId = null,
        ?string $accountProfileId = null
    ): LengthAwarePaginator {
        $query = Event::query();

        if ($includeArchived && $isAdminContext) {
            $query->onlyTrashed();
        }

        if ($accountContext) {
            $this->applyAccountFiltersToQuery($query, (string) $accountContext->_id, '');
        } elseif ($accountId || $accountProfileId) {
            $this->applyAccountFiltersToQuery(
                $query,
                (string) ($accountId ?? ''),
                (string) ($accountProfileId ?? '')
            );
        }

        $search = trim((string) ($queryParams['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('title', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%')
                    ->orWhere('venue.display_name', 'like', '%' . $search . '%');
            });
        }

        if (array_key_exists('status', $queryParams) && $queryParams['status'] !== null) {
            $query->where('publication.status', $queryParams['status']);
        }

        if (! $isAdminContext) {
            $this->applyPublicPublicationFilter($query);
        }

        return $query
            ->orderBy('date_time_start', 'desc')
            ->paginate($perPage)
            ->through(fn (Event $event): array => $this->formatManagementEvent($event));
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

        return Event::query()->where('slug', $eventId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatManagementEvent(Event $event): array
    {
        $payload = $this->formatEvent($event);

        $publication = $event->publication ?? null;
        $publication = is_array($publication) ? $publication : (array) $publication;
        $publishAt = $publication['publish_at'] ?? null;
        if ($publishAt instanceof \MongoDB\BSON\UTCDateTime) {
            $publishAt = $publishAt->toDateTime();
        }
        if ($publishAt instanceof \DateTimeInterface) {
            $publishAt = $publishAt->format(\DateTimeInterface::ATOM);
        }

        $payload['publication'] = [
            'status' => $publication['status'] ?? 'draft',
            'publish_at' => $publishAt,
        ];
        $payload['venue_id'] = $payload['venue']['id'] ?? null;
        $payload['artist_ids'] = array_values(array_filter(array_map(
            static fn ($artist): ?string => is_array($artist) ? (string) ($artist['id'] ?? '') : null,
            $payload['artists'] ?? []
        )));
        $payload['created_at'] = $event->created_at?->toJSON();
        $payload['updated_at'] = $event->updated_at?->toJSON();
        $payload['deleted_at'] = $event->deleted_at?->toJSON();

        return $payload;
    }

    public function eventBelongsToAccount(Event $event, Account $account): bool
    {
        $accountId = (string) $account->_id;

        if ((string) ($event->account_id ?? '') === $accountId) {
            return true;
        }

        $profileId = $event->account_profile_id
            ?? ($event->venue['id'] ?? null);

        if (! $profileId) {
            return false;
        }

        return AccountProfile::query()
            ->where('_id', (string) $profileId)
            ->where('account_id', $accountId)
            ->exists();
    }

    public function assertPublicVisible(Event $event): void
    {
        $publication = $event->publication ?? [];
        $publication = is_array($publication) ? $publication : (array) $publication;
        $status = (string) ($publication['status'] ?? 'published');
        $publishAt = $publication['publish_at'] ?? null;

        if ($status !== 'published') {
            abort(404, 'Event not found.');
        }

        if ($publishAt instanceof \MongoDB\BSON\UTCDateTime) {
            $publishAt = $publishAt->toDateTime();
        }

        if ($publishAt instanceof \DateTimeInterface && $publishAt > Carbon::now()) {
            abort(404, 'Event not found.');
        }
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<int, array{event_id: string, type: string, updated_at: string}>
     */
    public function buildStreamDeltas(array $queryParams, ?AccountUser $user, ?string $lastEventId): array
    {
        $since = $this->parseSince($lastEventId);
        if (! $since) {
            return [];
        }

        $filters = $this->normalizeFilters($queryParams);
        $raw = $this->runStreamQuery($filters, $user, $since, true);

        return array_values(array_filter(array_map(function ($event) use ($since): ?array {
            $payload = $this->formatStreamDelta($event, $since);

            return $payload['type'] ? $payload : null;
        }, $raw)));
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $queryParams): array
    {
        $originLat = Arr::get($queryParams, 'origin_lat');
        $originLng = Arr::get($queryParams, 'origin_lng');

        $originLat = is_numeric($originLat) ? (float) $originLat : null;
        $originLng = is_numeric($originLng) ? (float) $originLng : null;

        $useGeo = $originLat !== null && $originLng !== null;

        return [
            'search' => trim((string) ($queryParams['search'] ?? '')),
            'account_id' => isset($queryParams['account_id']) ? (string) $queryParams['account_id'] : null,
            'account_profile_id' => isset($queryParams['account_profile_id']) ? (string) $queryParams['account_profile_id'] : null,
            'categories' => $this->normalizeStringArray($queryParams['categories'] ?? []),
            'tags' => $this->normalizeStringArray($queryParams['tags'] ?? []),
            'taxonomy' => $this->normalizeTaxonomyArray($queryParams['taxonomy'] ?? []),
            'past_only' => filter_var($queryParams['past_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'confirmed_only' => filter_var($queryParams['confirmed_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'max_distance_meters' => $useGeo ? $this->resolveMaxDistanceMeters($queryParams) : null,
            'use_geo' => $useGeo,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function runAgendaQuery(array $filters, ?AccountUser $user, int $skip, int $limit, bool $useGeo): array
    {
        $pipeline = $this->buildAgendaPipeline($filters, $user, $skip, $limit, $useGeo);

        /** @var Collection<int, Event> $events */
        $events = Event::raw(fn ($collection) => $collection->aggregate($pipeline));

        return $events->all();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function runStreamQuery(array $filters, ?AccountUser $user, Carbon $since, bool $useGeo): array
    {
        $pipeline = $this->buildStreamPipeline($filters, $user, $since, $useGeo);

        /** @var Collection<int, Event> $events */
        $events = Event::raw(fn ($collection) => $collection->aggregate($pipeline));

        return $events->all();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildAgendaPipeline(array $filters, ?AccountUser $user, int $skip, int $limit, bool $useGeo): array
    {
        $now = new UTCDateTime(Carbon::now());
        $pipeline = [];

        $baseMatch = [
            'deleted_at' => null,
        ];

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

        $this->applyAccountFilter($pipeline, $filters['account_id'] ?? null, $filters['account_profile_id'] ?? null);
        $this->applyPublicationFilter($pipeline, $now);
        $this->applySearchFilter($pipeline, $filters['search']);
        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTagsFilter($pipeline, $filters['tags']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy']);
        $this->applyConfirmedFilter($pipeline, $filters['confirmed_only'], $user);

        $pipeline[] = [
            '$addFields' => [
                'effective_end' => [
                    '$ifNull' => [
                        '$date_time_end',
                        [
                            '$add' => ['$date_time_start', self::DEFAULT_EVENT_DURATION_MS],
                        ],
                    ],
                ],
            ],
        ];

        if ($filters['past_only']) {
            $pipeline[] = ['$match' => ['$expr' => ['$lte' => ['$effective_end', $now]]]];
            $sort = ['date_time_start' => -1];
        } else {
            $pipeline[] = ['$match' => ['$expr' => ['$gt' => ['$effective_end', $now]]]];
            $sort = ['date_time_start' => 1];
        }

        $pipeline[] = ['$sort' => $sort];
        $pipeline[] = ['$skip' => $skip];
        $pipeline[] = ['$limit' => $limit];

        return $pipeline;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildStreamPipeline(array $filters, ?AccountUser $user, Carbon $since, bool $useGeo): array
    {
        $sinceUtc = new UTCDateTime($since);
        $now = new UTCDateTime(Carbon::now());
        $pipeline = [];

        $baseMatch = [
            '$or' => [
                ['updated_at' => ['$gt' => $sinceUtc]],
                ['deleted_at' => ['$gt' => $sinceUtc]],
            ],
        ];

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

        $this->applyAccountFilter($pipeline, $filters['account_id'] ?? null, $filters['account_profile_id'] ?? null);
        $this->applyPublicationFilter($pipeline, $now);
        $this->applySearchFilter($pipeline, $filters['search']);
        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTagsFilter($pipeline, $filters['tags']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy']);
        $this->applyConfirmedFilter($pipeline, $filters['confirmed_only'], $user);

        $pipeline[] = ['$sort' => ['updated_at' => 1]];

        return $pipeline;
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    private function applySearchFilter(array &$pipeline, string $search): void
    {
        if ($search === '') {
            return;
        }

        $pattern = new Regex(preg_quote($search), 'i');

        $pipeline[] = [
            '$match' => [
                '$or' => [
                    ['title' => ['$regex' => $pattern]],
                    ['content' => ['$regex' => $pattern]],
                    ['artists.display_name' => ['$regex' => $pattern]],
                    ['venue.display_name' => ['$regex' => $pattern]],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     * @param array<int, string> $categories
     */
    private function applyCategoryFilter(array &$pipeline, array $categories): void
    {
        if ($categories === []) {
            return;
        }

        $regexes = array_map(
            static fn (string $value): Regex => new Regex('^' . preg_quote($value) . '$', 'i'),
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
     * @param array<int, array<string, mixed>> $pipeline
     * @param array<int, string> $tags
     */
    private function applyTagsFilter(array &$pipeline, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $regexes = array_map(
            static fn (string $value): Regex => new Regex('^' . preg_quote($value) . '$', 'i'),
            $tags
        );

        $pipeline[] = [
            '$match' => [
                'tags' => ['$in' => $regexes],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     * @param array<int, array{type: string, value: string}> $taxonomy
     */
    private function applyTaxonomyFilter(array &$pipeline, array $taxonomy): void
    {
        if ($taxonomy === []) {
            return;
        }

        $termMatches = [];

        foreach ($taxonomy as $term) {
            $typeRegex = new Regex('^' . preg_quote($term['type']) . '$', 'i');
            $valueRegex = new Regex('^' . preg_quote($term['value']) . '$', 'i');

            $termMatches[] = [
                'venue.taxonomy_terms' => [
                    '$elemMatch' => [
                        'type' => ['$regex' => $typeRegex],
                        'value' => ['$regex' => $valueRegex],
                    ],
                ],
            ];

            $termMatches[] = [
                'artists.taxonomy_terms' => [
                    '$elemMatch' => [
                        'type' => ['$regex' => $typeRegex],
                        'value' => ['$regex' => $valueRegex],
                    ],
                ],
            ];

            $termMatches[] = [
                'taxonomy_terms' => [
                    '$elemMatch' => [
                        'type' => ['$regex' => $typeRegex],
                        'value' => ['$regex' => $valueRegex],
                    ],
                ],
            ];
        }

        if ($termMatches !== []) {
            $pipeline[] = ['$match' => ['$or' => $termMatches]];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    private function applyConfirmedFilter(array &$pipeline, bool $confirmedOnly, ?AccountUser $user): void
    {
        if (! $confirmedOnly || ! $user) {
            return;
        }

        $pipeline[] = [
            '$match' => [
                'confirmed_user_ids' => (string) $user->_id,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    private function applyAccountFilter(array &$pipeline, ?string $accountId, ?string $accountProfileId): void
    {
        if ($accountProfileId !== null && $accountProfileId !== '') {
            $pipeline[] = [
                '$match' => [
                    '$or' => [
                        ['account_profile_id' => $accountProfileId],
                        ['venue.id' => $accountProfileId],
                    ],
                ],
            ];

            return;
        }

        if ($accountId === null || $accountId === '') {
            return;
        }

        $profileIds = $this->resolveAccountProfileIds($accountId);

        $match = [
            '$or' => [
                ['account_id' => $accountId],
            ],
        ];

        if ($profileIds !== []) {
            $match['$or'][] = ['venue.id' => ['$in' => $profileIds]];
        }

        $pipeline[] = ['$match' => $match];
    }

    /**
     * @return array<int, string>
     */
    private function resolveAccountProfileIds(string $accountId): array
    {
        return AccountProfile::query()
            ->where('account_id', $accountId)
            ->pluck('_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $pipeline
     */
    private function applyPublicationFilter(array &$pipeline, UTCDateTime $now): void
    {
        $pipeline[] = [
            '$addFields' => [
                'publication_status' => [
                    '$ifNull' => ['$publication.status', 'published'],
                ],
                'publish_at_effective' => [
                    '$ifNull' => ['$publication.publish_at', '$created_at'],
                ],
            ],
        ];

        $pipeline[] = [
            '$match' => [
                '$expr' => [
                    '$and' => [
                        ['$eq' => ['$publication_status', 'published']],
                        ['$lte' => ['$publish_at_effective', $now]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, mixed> $items
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
        $settings = TenantSettings::current();
        $mapUi = $settings?->getAttribute('map_ui') ?? [];
        $radius = is_array($mapUi) ? ($mapUi['radius'] ?? []) : [];
        $radius = is_array($radius) ? $radius : [];

        $min = isset($radius['min_km']) ? (float) $radius['min_km'] : self::DEFAULT_RADIUS_MIN_KM;
        $default = isset($radius['default_km']) ? (float) $radius['default_km'] : self::DEFAULT_RADIUS_KM;
        $max = isset($radius['max_km']) ? (float) $radius['max_km'] : self::DEFAULT_RADIUS_MAX_KM;

        if ($min <= 0) {
            $min = self::DEFAULT_RADIUS_MIN_KM;
        }
        if ($max <= 0) {
            $max = self::DEFAULT_RADIUS_MAX_KM;
        }
        if ($default <= 0) {
            $default = self::DEFAULT_RADIUS_KM;
        }

        return [
            'min_km' => $min,
            'default_km' => min(max($default, $min), $max),
            'max_km' => $max,
        ];
    }

    private function parseSince(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $event
     * @return array<string, mixed>
     */
    public function formatEvent(mixed $event, ?AccountUser $user = null): array
    {
        $type = $this->normalizeArray($event->type ?? null);
        $venue = $this->normalizeArray($event->venue ?? null);
        $thumb = $this->normalizeArray($event->thumb ?? null);
        $artists = $this->normalizeArray($event->artists ?? []);
        $tags = $this->normalizeArray($event->tags ?? []);
        $taxonomyTerms = $this->normalizeArray($event->taxonomy_terms ?? []);

        $artists = array_map(function ($artist): array {
            $payload = $this->normalizeArray($artist);
            $displayName = $payload['display_name'] ?? $payload['name'] ?? null;

            return [
                'id' => isset($payload['id']) ? (string) $payload['id'] : '',
                'display_name' => (string) ($displayName ?? ''),
                'avatar_url' => $payload['avatar_url'] ?? null,
                'highlight' => (bool) ($payload['highlight'] ?? false),
                'genres' => array_values($this->normalizeStringArray($payload['genres'] ?? [])),
            ];
        }, $artists);

        $venueDisplay = $venue['display_name'] ?? $venue['name'] ?? null;
        $venuePayload = $venue === [] ? null : [
            'id' => isset($venue['id']) ? (string) $venue['id'] : '',
            'display_name' => (string) ($venueDisplay ?? ''),
            'tagline' => $venue['tagline'] ?? null,
            'hero_image_url' => $venue['hero_image_url'] ?? null,
            'logo_url' => $venue['logo_url'] ?? null,
            'taxonomy_terms' => $venue['taxonomy_terms'] ?? [],
        ];

        $geo = $this->normalizeArray($event->geo_location ?? null);
        $coordinates = $geo['coordinates'] ?? null;
        $lat = null;
        $lng = null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lng = (float) $coordinates[0];
            $lat = (float) $coordinates[1];
        }

        $confirmedIds = $this->normalizeArray($event->confirmed_user_ids ?? []);
        $confirmedIds = array_values(array_filter(array_map(static fn ($id): string => (string) $id, $confirmedIds)));
        $isConfirmed = $user ? in_array((string) $user->_id, $confirmedIds, true) : false;

        return [
            'id' => isset($event->_id) ? (string) $event->_id : '',
            'slug' => (string) ($event->slug ?? ''),
            'type' => [
                'id' => isset($type['id']) ? (string) $type['id'] : '',
                'name' => (string) ($type['name'] ?? ''),
                'slug' => (string) ($type['slug'] ?? ''),
                'description' => (string) ($type['description'] ?? ''),
                'icon' => $type['icon'] ?? null,
                'color' => $type['color'] ?? null,
            ],
            'title' => (string) ($event->title ?? ''),
            'content' => (string) ($event->content ?? ''),
            'venue' => $venuePayload,
            'latitude' => $lat,
            'longitude' => $lng,
            'thumb' => $thumb === [] ? null : $thumb,
            'date_time_start' => $this->formatDate($event->date_time_start ?? null),
            'date_time_end' => $this->formatDate($event->date_time_end ?? null),
            'artists' => $artists,
            'is_confirmed' => $isConfirmed,
            'total_confirmed' => count($confirmedIds),
            'received_invites' => $this->normalizeArray($event->received_invites ?? []),
            'sent_invites' => $this->normalizeArray($event->sent_invites ?? []),
            'friends_going' => $this->normalizeArray($event->friends_going ?? []),
            'tags' => array_values(array_map('strval', $tags)),
            'taxonomy_terms' => $taxonomyTerms,
        ];
    }

    /**
     * @param mixed $event
     * @return array{event_id: string, type: string, updated_at: string}
     */
    private function formatStreamDelta(mixed $event, Carbon $since): array
    {
        $updatedAt = $this->formatDate($event->updated_at ?? null);
        $deletedAt = $this->formatDate($event->deleted_at ?? null);
        $createdAt = $this->formatDate($event->created_at ?? null);

        $type = null;
        if ($deletedAt !== null) {
            $type = 'event.deleted';
        } elseif ($createdAt !== null) {
            $created = Carbon::parse($createdAt);
            if ($created->greaterThan($since)) {
                $type = 'event.created';
            } else {
                $type = 'event.updated';
            }
        }

        return [
            'event_id' => isset($event->_id) ? (string) $event->_id : '',
            'type' => $type ?? 'event.updated',
            'updated_at' => $updatedAt ?? $deletedAt ?? $createdAt ?? Carbon::now()->toISOString(),
        ];
    }

    /**
     * @param mixed $value
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

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return null;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }

    private function applyAccountFiltersToQuery($query, string $accountId, string $accountProfileId): void
    {
        if ($accountProfileId !== '') {
            $query->where(function ($builder) use ($accountProfileId): void {
                $builder->where('account_profile_id', $accountProfileId)
                    ->orWhere('venue.id', $accountProfileId);
            });

            return;
        }

        if ($accountId === '') {
            return;
        }

        $profileIds = $this->resolveAccountProfileIds($accountId);

        $query->where(function ($builder) use ($accountId, $profileIds): void {
            $builder->where('account_id', $accountId);
            if ($profileIds !== []) {
                $builder->orWhereIn('venue.id', $profileIds);
            }
        });
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
}
