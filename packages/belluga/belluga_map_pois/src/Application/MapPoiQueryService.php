<?php

declare(strict_types=1);

namespace Belluga\MapPois\Application;

use Belluga\MapPois\Application\Concerns\MapPoiQueryFormatting;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;

class MapPoiQueryService
{
    use MapPoiQueryFormatting;

    private const EVENT_DOMINANCE_RADIUS_METERS = 50.0;

    public function __construct(
        private readonly MapPoiSettingsContract $settings,
        private readonly MapPoiTenantContextContract $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    public function stacks(array $queryParams, ?string $timezone): array
    {
        $queryParams = $this->normalizeViewportQuery($queryParams);
        $stackKey = trim((string) ($queryParams['stack_key'] ?? ''));
        $bounds = $this->resolveBounds($queryParams);
        $serverTime = Carbon::now()->toJSON();

        if ($stackKey !== '') {
            $items = $this->resolveStackItems($queryParams, $timezone, $stackKey);
            $stack = $this->formatStack($stackKey, $items);

            return [
                'tenant_id' => $this->resolveTenantId(),
                'server_time' => $serverTime,
                'bounds' => $bounds,
                'is_partial' => false,
                'stacks' => $stack ? [$stack] : [],
            ];
        }

        $scene = $this->resolveDominantStacks($queryParams, $timezone);

        return [
            'tenant_id' => $this->resolveTenantId(),
            'server_time' => $serverTime,
            'bounds' => $bounds,
            'is_partial' => $scene['is_partial'],
            'stacks' => $scene['stacks'],
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    public function near(array $queryParams, ?string $timezone): array
    {
        $queryParams = $this->normalizeNearQuery($queryParams);
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $pageSize = (int) ($queryParams['page_size'] ?? 10);
        if ($pageSize <= 0) {
            $pageSize = 10;
        }
        if ($pageSize > 50) {
            $pageSize = 50;
        }

        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true, true);
        $pipeline[] = ['$sort' => $this->nearSort($queryParams)];
        $skip = ($page - 1) * $pageSize;
        $limit = $pageSize + 1;

        $pipeline[] = ['$skip' => $skip];
        $pipeline[] = ['$limit' => $limit];

        $items = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        $formatted = [];
        foreach ($items as $item) {
            $formatted[] = $this->formatNearItem($item);
        }

        $hasMore = count($formatted) > $pageSize;
        if ($hasMore) {
            $formatted = array_slice($formatted, 0, $pageSize);
        }

        return [
            'tenant_id' => $this->resolveTenantId(),
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => $hasMore,
            'items' => $formatted,
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>|null
     */
    public function lookup(array $queryParams, ?string $timezone): ?array
    {
        $resolvedRefType = $this->mapSourceToRefType((string) ($queryParams['ref_type'] ?? ''));
        $resolvedRefId = trim((string) ($queryParams['ref_id'] ?? ''));

        if ($resolvedRefType === null || $resolvedRefId === '') {
            return null;
        }

        $match = $this->buildMatchConditions([], $timezone);
        $match['ref_type'] = $resolvedRefType;
        $match['ref_id'] = $resolvedRefId;

        $pipeline = [
            ['$match' => $match],
            ['$limit' => 1],
        ];

        $items = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        foreach ($items as $item) {
            $payload = $this->formatTopPoi($item);
            $poiData = $this->normalizeDocument($item);
            $stackKey = trim((string) ($poiData['exact_key'] ?? ''));

            if ($stackKey !== '') {
                $payload['stack_key'] = $stackKey;
            }

            return [
                'tenant_id' => $this->resolveTenantId(),
                'poi' => $payload,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function resolveBounds(array $queryParams): array
    {
        $bounds = [
            'ne_lat' => $this->toFloat($queryParams['ne_lat'] ?? null),
            'ne_lng' => $this->toFloat($queryParams['ne_lng'] ?? null),
            'sw_lat' => $this->toFloat($queryParams['sw_lat'] ?? null),
            'sw_lng' => $this->toFloat($queryParams['sw_lng'] ?? null),
        ];

        return $bounds;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function resolveStackItems(array $queryParams, ?string $timezone, string $stackKey): array
    {
        $items = $this->loadStackDocuments($queryParams, $timezone, $stackKey);
        $dominantItems = $this->applyIntraStackEventDominance($items);

        $formatted = [];
        foreach ($dominantItems as $item) {
            $formatted[] = $this->formatTopPoi($item);
        }

        return $formatted;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array{is_partial: bool, stacks: array<int, array<string, mixed>>}
     */
    private function resolveDominantStacks(array $queryParams, ?string $timezone): array
    {
        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true);
        $pipeline[] = ['$limit' => 51];

        $rows = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        $sample = [];
        foreach ($rows as $row) {
            $sample[] = $this->normalizeDocument($row);
        }
        $isPartial = count($sample) > 50;
        $sample = array_slice($sample, 0, 50);

        $grouped = [];
        foreach ($sample as $item) {
            $stackKey = trim((string) ($item['exact_key'] ?? ''));
            if ($stackKey === '') {
                $stackKey = (string) ($item['ref_type'] ?? '').':'.(string) ($item['ref_id'] ?? '');
            }
            $grouped[$stackKey][] = $item;
        }

        $candidates = [];
        $eventCenters = [];
        foreach ($grouped as $stackKey => $items) {
            $dominantItems = $this->applyIntraStackEventDominance($items);
            usort($dominantItems, fn (array $left, array $right): int => $this->compareSamplePois($left, $right));
            $topPoi = $dominantItems[0];
            $hasEvent = ($topPoi['ref_type'] ?? null) === 'event';
            $center = $topPoi['location'] ?? null;
            $distance = min(array_map(
                static fn (array $item): float => (float) ($item['distance_meters'] ?? INF),
                $dominantItems,
            ));

            $candidate = [
                'stack_key' => $stackKey,
                'center' => $center,
                'stack_count' => count($dominantItems),
                'top_poi' => $topPoi,
                'has_event' => $hasEvent,
                'distance_meters' => $distance,
            ];
            if ($hasEvent) {
                $coordinates = $this->extractCoordinates($center);
                if ($coordinates !== null) {
                    $eventCenters[] = $coordinates;
                }
            }
            $candidates[] = $candidate;
        }

        usort($candidates, function (array $left, array $right): int {
            $distanceOrder = ($left['distance_meters'] <=> $right['distance_meters']);
            if ($distanceOrder !== 0) {
                return $distanceOrder;
            }
            $poiOrder = $this->compareSamplePois($left['top_poi'], $right['top_poi']);

            return $poiOrder !== 0
                ? $poiOrder
                : strcmp((string) $left['stack_key'], (string) $right['stack_key']);
        });

        $stacks = [];
        foreach ($candidates as $candidate) {
            if (
                ! $candidate['has_event']
                && $this->isWithinEventDominanceRadius(
                    $candidate['center'] ?? ($candidate['top_poi']['location'] ?? null),
                    $eventCenters
                )
            ) {
                continue;
            }

            $stacks[] = [
                'stack_key' => $candidate['stack_key'],
                'center' => $this->formatLocation($candidate['center']),
                'stack_count' => $candidate['stack_count'],
                'top_poi' => $this->formatTopPoi($candidate['top_poi']),
            ];
        }

        return [
            'is_partial' => $isPartial,
            'stacks' => array_values($stacks),
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareSamplePois(array $left, array $right): int
    {
        $typeOrder = $this->refTypeOrder((string) ($left['ref_type'] ?? ''))
            <=> $this->refTypeOrder((string) ($right['ref_type'] ?? ''));
        if ($typeOrder !== 0) {
            return $typeOrder;
        }
        $priorityOrder = (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0);

        return $priorityOrder !== 0
            ? $priorityOrder
            : strcmp((string) ($left['ref_id'] ?? ''), (string) ($right['ref_id'] ?? ''));
    }

    private function refTypeOrder(string $refType): int
    {
        return match ($refType) {
            'event' => 1,
            'account_profile' => 2,
            'static' => 3,
            default => 9,
        };
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function loadStackDocuments(array $queryParams, ?string $timezone, string $stackKey): array
    {
        $queryParams['stack_key'] = $stackKey;
        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true);
        $pipeline[] = $this->buildRefTypeOrderStage();
        $pipeline[] = $this->buildStackSortStage();

        $items = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = $this->normalizeDocument($item);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function applyIntraStackEventDominance(array $items): array
    {
        $events = [];

        foreach ($items as $item) {
            if (($item['ref_type'] ?? null) === 'event') {
                $events[] = $item;
            }
        }

        return $events !== [] ? array_values($events) : array_values($items);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRefTypeOrderStage(): array
    {
        return [
            '$addFields' => [
                'ref_type_order' => [
                    '$switch' => [
                        'branches' => [
                            ['case' => ['$eq' => ['$ref_type', 'event']], 'then' => 1],
                            ['case' => ['$eq' => ['$ref_type', 'account_profile']], 'then' => 2],
                            ['case' => ['$eq' => ['$ref_type', 'static']], 'then' => 3],
                        ],
                        'default' => 9,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStackSortStage(): array
    {
        return [
            '$sort' => [
                'ref_type_order' => 1,
                'priority' => -1,
                'ref_id' => 1,
            ],
        ];
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $eventCenters
     */
    private function isWithinEventDominanceRadius(mixed $location, array $eventCenters): bool
    {
        $coordinates = $this->extractCoordinates($location);
        if ($coordinates === null) {
            return false;
        }

        foreach ($eventCenters as $eventCenter) {
            if (
                $this->distanceMetersBetween($coordinates, $eventCenter)
                <= self::EVENT_DOMINANCE_RADIUS_METERS
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function extractCoordinates(mixed $location): ?array
    {
        if (! is_array($location)) {
            $location = $this->normalizeDocument($location);
        }

        $coordinates = $location['coordinates'] ?? null;
        if (is_array($coordinates) && count($coordinates) >= 2) {
            return [
                'lat' => (float) $coordinates[1],
                'lng' => (float) $coordinates[0],
            ];
        }

        if (
            array_key_exists('lat', $location)
            && array_key_exists('lng', $location)
        ) {
            return [
                'lat' => (float) $location['lat'],
                'lng' => (float) $location['lng'],
            ];
        }

        return null;
    }

    /**
     * @param  array{lat: float, lng: float}  $from
     * @param  array{lat: float, lng: float}  $to
     */
    private function distanceMetersBetween(array $from, array $to): float
    {
        $earthRadiusMeters = 6371000.0;

        $latFrom = deg2rad($from['lat']);
        $latTo = deg2rad($to['lat']);
        $latDelta = deg2rad($to['lat'] - $from['lat']);
        $lngDelta = deg2rad($to['lng'] - $from['lng']);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }

    /** @param array<string, mixed> $queryParams @return array<string, mixed> */
    private function normalizeViewportQuery(array $queryParams): array
    {
        $neLat = $this->toFloat($queryParams['ne_lat'] ?? null);
        $neLng = $this->toFloat($queryParams['ne_lng'] ?? null);
        $swLat = $this->toFloat($queryParams['sw_lat'] ?? null);
        $swLng = $this->toFloat($queryParams['sw_lng'] ?? null);

        if (
            $neLat === null || $neLng === null || $swLat === null || $swLng === null
            || ! is_finite($neLat) || ! is_finite($neLng)
            || ! is_finite($swLat) || ! is_finite($swLng)
            || $swLat >= $neLat || $swLng >= $neLng
        ) {
            throw ValidationException::withMessages([
                'viewport' => ['map_viewport_invalid'],
            ]);
        }

        $origin = [
            'lat' => ($neLat + $swLat) / 2,
            'lng' => ($neLng + $swLng) / 2,
        ];
        $corners = [
            ['lat' => $neLat, 'lng' => $neLng],
            ['lat' => $neLat, 'lng' => $swLng],
            ['lat' => $swLat, 'lng' => $neLng],
            ['lat' => $swLat, 'lng' => $swLng],
        ];
        $coveringRadius = max(array_map(
            fn (array $corner): float => $this->distanceMetersBetween($origin, $corner),
            $corners,
        ));
        $radius = $this->resolveRadiusSettings();
        if ($coveringRadius > ($radius['max_km'] * 1000)) {
            throw ValidationException::withMessages([
                'viewport' => ['map_viewport_too_large'],
            ]);
        }
        $effectiveRadius = max($coveringRadius, $radius['min_km'] * 1000);

        $requestedLat = $this->toFloat($queryParams['origin_lat'] ?? null);
        $requestedLng = $this->toFloat($queryParams['origin_lng'] ?? null);
        $requestedRadius = $this->toFloat($queryParams['max_distance_meters'] ?? null);
        if (
            ($requestedLat !== null && abs($requestedLat - $origin['lat']) > 0.000001)
            || ($requestedLng !== null && abs($requestedLng - $origin['lng']) > 0.000001)
            || ($requestedRadius !== null && abs($requestedRadius - $effectiveRadius) > 1.0)
        ) {
            throw ValidationException::withMessages([
                'viewport' => ['map_viewport_geometry_mismatch'],
            ]);
        }

        $queryParams['origin_lat'] = $origin['lat'];
        $queryParams['origin_lng'] = $origin['lng'];
        $queryParams['max_distance_meters'] = $effectiveRadius;
        $queryParams['_viewport_scene'] = true;

        return $queryParams;
    }

    /** @param array<string, mixed> $queryParams @return array<string, mixed> */
    private function normalizeNearQuery(array $queryParams): array
    {
        $radius = $this->resolveRadiusSettings();
        $requestedMeters = $this->toFloat($queryParams['max_distance_meters'] ?? null);
        $requestedKm = $requestedMeters !== null && is_finite($requestedMeters) && $requestedMeters > 0
            ? $requestedMeters / 1000
            : $radius['default_km'];
        $effectiveKm = min(max($requestedKm, $radius['min_km']), $radius['max_km']);
        $queryParams['max_distance_meters'] = $effectiveKm * 1000;

        return $queryParams;
    }

    /** @return array{min_km: float, default_km: float, max_km: float} */
    private function resolveRadiusSettings(): array
    {
        $fallback = ['min_km' => 0.5, 'default_km' => 5.0, 'max_km' => 50.0];
        $mapUi = $this->normalizeDocument($this->settings->resolveMapUiSettings());
        $configured = $this->normalizeDocument($mapUi['radius'] ?? null);
        $resolved = [];
        foreach ($fallback as $key => $fallbackValue) {
            $value = $this->toFloat($configured[$key] ?? null);
            $resolved[$key] = $value !== null && is_finite($value) && $value > 0
                ? $value
                : $fallbackValue;
        }
        if ($resolved['min_km'] > $resolved['max_km']) {
            return $fallback;
        }
        $resolved['default_km'] = min(
            max($resolved['default_km'], $resolved['min_km']),
            $resolved['max_km'],
        );

        return $resolved;
    }

    /** @param array<string, mixed> $queryParams @return array<string, int> */
    private function nearSort(array $queryParams): array
    {
        if ($this->mapSourceToRefType((string) ($queryParams['source'] ?? '')) === 'event') {
            return [
                'time_start' => 1,
                'distance_meters' => 1,
                'ref_id' => 1,
            ];
        }

        return [
            'distance_meters' => 1,
            'priority' => -1,
            'ref_id' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function buildBasePipeline(
        array $queryParams,
        ?string $timezone,
        bool $includeDistance,
        bool $forceGeoNear = false
    ): array {
        $originLat = $this->toFloat($queryParams['origin_lat'] ?? null);
        $originLng = $this->toFloat($queryParams['origin_lng'] ?? null);
        $maxDistance = $this->toFloat($queryParams['max_distance_meters'] ?? null);

        $match = $this->buildMatchConditions($queryParams, $timezone);
        $geoMatch = $this->buildGeoWithinMatch($queryParams);

        $pipeline = [];

        if (($originLat !== null && $originLng !== null) || $forceGeoNear) {
            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $originLng, (float) $originLat],
                ],
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => array_merge($match, $geoMatch),
            ];

            if ($maxDistance !== null) {
                $geoNear['maxDistance'] = (float) $maxDistance;
            }

            $pipeline[] = ['$geoNear' => $geoNear];
            if ($geoMatch !== []) {
                $pipeline[] = ['$match' => $geoMatch];
            }
        } else {
            $pipeline[] = ['$match' => array_merge($match, $geoMatch)];
        }

        return $pipeline;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function buildMatchConditions(array $queryParams, ?string $timezone): array
    {
        $match = [
            'is_active' => true,
        ];

        $source = strtolower(trim((string) ($queryParams['source'] ?? '')));
        if ($source !== '') {
            $refType = $this->mapSourceToRefType($source);
            if ($refType !== null) {
                $match['ref_type'] = $refType;
            }
        }

        $types = $this->normalizeStringArray($queryParams['types'] ?? []);
        if ($types !== []) {
            $match['source_type'] = ['$in' => $types];
        }

        $categories = $this->normalizeStringArray($queryParams['categories'] ?? []);
        if ($categories !== []) {
            $match['category'] = ['$in' => $categories];
        }

        $tags = $this->normalizeStringArray($queryParams['tags'] ?? []);
        if ($tags !== []) {
            $match['tags'] = ['$in' => $tags];
        }

        $taxonomy = $this->normalizeStringArray($queryParams['taxonomy'] ?? []);
        if ($taxonomy !== []) {
            $match['taxonomy_terms_flat'] = ['$in' => $taxonomy];
        }

        $search = trim((string) ($queryParams['search'] ?? ''));
        if ($search !== '') {
            $match['name'] = ['$regex' => preg_quote($search, '/'), '$options' => 'i'];
        }

        $stackKey = trim((string) ($queryParams['stack_key'] ?? ''));
        if ($stackKey !== '') {
            $match['exact_key'] = $stackKey;
        }

        $window = $this->resolveWindowBounds($timezone);
        $match['$and'] = [
            [
                '$or' => [
                    ['active_window_start_at' => ['$exists' => false]],
                    ['active_window_start_at' => null],
                    ['active_window_start_at' => ['$lte' => $window['future']]],
                ],
            ],
            [
                '$or' => [
                    ['active_window_end_at' => ['$exists' => false]],
                    ['active_window_end_at' => null],
                    ['active_window_end_at' => ['$gte' => $window['past']]],
                ],
            ],
        ];

        return $match;
    }

    private function mapSourceToRefType(string $source): ?string
    {
        return match (strtolower(trim($source))) {
            'event' => 'event',
            'account_profile', 'account' => 'account_profile',
            'static', 'static_asset', 'asset' => 'static',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function buildGeoWithinMatch(array $queryParams): array
    {
        $neLat = $this->toFloat($queryParams['ne_lat'] ?? null);
        $neLng = $this->toFloat($queryParams['ne_lng'] ?? null);
        $swLat = $this->toFloat($queryParams['sw_lat'] ?? null);
        $swLng = $this->toFloat($queryParams['sw_lng'] ?? null);

        if ($neLat === null || $neLng === null || $swLat === null || $swLng === null) {
            return [];
        }

        $locationWithin = [
            'location' => [
                '$geoWithin' => [
                    '$box' => [
                        [(float) $swLng, (float) $swLat],
                        [(float) $neLng, (float) $neLat],
                    ],
                ],
            ],
        ];

        $boxPolygon = [
            'type' => 'Polygon',
            'coordinates' => [[
                [(float) $swLng, (float) $swLat],
                [(float) $neLng, (float) $swLat],
                [(float) $neLng, (float) $neLat],
                [(float) $swLng, (float) $neLat],
                [(float) $swLng, (float) $swLat],
            ]],
        ];

        return [
            '$or' => [
                $locationWithin,
                [
                    'discovery_scope.type' => 'polygon',
                    'discovery_scope.polygon' => [
                        '$geoIntersects' => [
                            '$geometry' => $boxPolygon,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{past: UTCDateTime, future: UTCDateTime}
     */
    private function resolveWindowBounds(?string $timezone): array
    {
        $mapUi = $this->settings->resolveMapUiSettings();
        $window = is_array($mapUi['poi_time_window_days'] ?? null) ? $mapUi['poi_time_window_days'] : [];

        $futureDays = (int) ($window['future'] ?? 30);
        $pastDays = (int) ($window['past'] ?? 1);

        if ($futureDays < 0) {
            $futureDays = 0;
        }
        if ($pastDays < 0) {
            $pastDays = 0;
        }

        $resolvedTimezone = $timezone ?: (string) config('app.timezone', 'UTC');

        try {
            $now = Carbon::now($resolvedTimezone);
        } catch (\Exception) {
            $resolvedTimezone = (string) config('app.timezone', 'UTC');
            $now = Carbon::now($resolvedTimezone);
        }

        $future = $now->copy()->addDays($futureDays)->endOfDay()->utc();
        $past = $now->copy()->subDays($pastDays)->startOfDay()->utc();

        return [
            'future' => new UTCDateTime($future),
            'past' => new UTCDateTime($past),
        ];
    }

    private function resolveTenantId(): ?string
    {
        return $this->tenantContext->currentTenantId();
    }
}
