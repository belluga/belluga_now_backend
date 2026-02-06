<?php

declare(strict_types=1);

namespace App\Application\Map;

use App\DataObjects\Settings\MapUiSettings;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\TenantSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Eloquent\Collection;

class MapPoiQueryService
{
    private const DEFAULT_RADIUS_MIN_KM = 1.0;
    private const DEFAULT_RADIUS_KM = 5.0;
    private const DEFAULT_RADIUS_MAX_KM = 50.0;
    private const DEFAULT_PAST_WINDOW_HOURS = 6.0;
    private const DEFAULT_FUTURE_WINDOW_HOURS = 720.0;

    /**
     * @param array<string, mixed> $queryParams
     * @return array{tenant_id: string, items: array<int, array<string, mixed>>}
     */
    public function fetchStacks(array $queryParams): array
    {
        $filters = $this->normalizeFilters($queryParams);
        $raw = $this->runPoiQuery($filters);
        $items = array_map(fn ($poi) => $this->formatPoi($poi), $raw);

        return [
            'tenant_id' => (string) Tenant::resolve()->_id,
            'items' => $this->groupStacks($items),
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function fetchFilters(array $queryParams): array
    {
        $filters = $this->normalizeFilters($queryParams);
        $raw = $this->runPoiQuery($filters);
        $items = array_map(fn ($poi) => $this->formatPoi($poi), $raw);

        return [
            'tenant_id' => (string) Tenant::resolve()->_id,
            'categories' => $this->buildCategoryFilters($items),
            'tags' => $this->buildTagFilters($items),
            'taxonomy_terms' => $this->buildTaxonomyFilters($items),
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<int, array{ref_type: string, ref_id: string, type: string, updated_at: string}>
     */
    public function buildStreamDeltas(array $queryParams, ?string $lastEventId): array
    {
        $since = $this->parseSince($lastEventId);
        if (! $since) {
            return [];
        }

        $filters = $this->normalizeFilters($queryParams);
        $raw = $this->runStreamQuery($filters, $since);

        return array_values(array_filter(array_map(function ($poi) use ($since): ?array {
            $payload = $this->formatStreamDelta($poi, $since);

            return $payload['type'] ? $payload : null;
        }, $raw)));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function runPoiQuery(array $filters): array
    {
        $pipeline = $this->buildPoiPipeline($filters);

        /** @var Collection<int, mixed> $pois */
        $pois = MapPoi::raw(fn ($collection) => $collection->aggregate($pipeline));

        return $pois->all();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, mixed>
     */
    private function runStreamQuery(array $filters, Carbon $since): array
    {
        $pipeline = $this->buildStreamPipeline($filters, $since);

        /** @var Collection<int, mixed> $pois */
        $pois = MapPoi::raw(fn ($collection) => $collection->aggregate($pipeline));

        return $pois->all();
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

        $viewport = $this->resolveViewport($queryParams);

        $useGeo = $originLat !== null && $originLng !== null;

        return [
            'search' => trim((string) ($queryParams['search'] ?? '')),
            'categories' => $this->normalizeStringArray($queryParams['categories'] ?? []),
            'tags' => $this->normalizeStringArray($queryParams['tags'] ?? []),
            'taxonomy' => $this->normalizeTaxonomyArray($queryParams['taxonomy'] ?? []),
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'max_distance_meters' => $useGeo ? $this->resolveMaxDistanceMeters($queryParams) : null,
            'viewport' => $viewport,
            'use_geo' => $useGeo,
            'sort' => $this->resolveSort((string) ($queryParams['sort'] ?? 'priority')),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildPoiPipeline(array $filters): array
    {
        $window = $this->resolveTimeWindow();
        $windowStart = new UTCDateTime(Carbon::now()->subHours($window['past_hours']));
        $windowEnd = new UTCDateTime(Carbon::now()->addHours($window['future_hours']));

        $pipeline = [];

        $baseMatch = [
            'deleted_at' => null,
            'is_active' => true,
            '$or' => [
                ['time_anchor_at' => null],
                ['time_anchor_at' => ['$gte' => $windowStart, '$lte' => $windowEnd]],
            ],
        ];

        $viewportMatch = $this->buildViewportMatch($filters['viewport'] ?? null);

        if ($filters['use_geo'] && $filters['origin_lat'] !== null && $filters['origin_lng'] !== null) {
            $query = $baseMatch;

            if ($viewportMatch !== null) {
                $query['location'] = $viewportMatch;
            } else {
                $query['location'] = ['$ne' => null];
            }

            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $filters['origin_lng'], (float) $filters['origin_lat']],
                ],
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => $query,
            ];

            if ($filters['max_distance_meters'] !== null) {
                $geoNear['maxDistance'] = (float) $filters['max_distance_meters'];
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        } else {
            $pipeline[] = ['$match' => $baseMatch];

            if ($viewportMatch !== null) {
                $pipeline[] = [
                    '$match' => [
                        'location' => $viewportMatch,
                    ],
                ];
            }
        }

        $this->applySearchFilter($pipeline, $filters['search']);
        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTagsFilter($pipeline, $filters['tags']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy']);

        $pipeline[] = ['$sort' => $filters['sort']];

        return $pipeline;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildStreamPipeline(array $filters, Carbon $since): array
    {
        $sinceUtc = new UTCDateTime($since);
        $window = $this->resolveTimeWindow();
        $windowStart = new UTCDateTime(Carbon::now()->subHours($window['past_hours']));
        $windowEnd = new UTCDateTime(Carbon::now()->addHours($window['future_hours']));

        $pipeline = [];

        $baseMatch = [
            '$or' => [
                ['updated_at' => ['$gt' => $sinceUtc]],
                ['deleted_at' => ['$gt' => $sinceUtc]],
            ],
            '$and' => [
                [
                    '$or' => [
                        ['time_anchor_at' => null],
                        ['time_anchor_at' => ['$gte' => $windowStart, '$lte' => $windowEnd]],
                    ],
                ],
            ],
        ];

        $viewportMatch = $this->buildViewportMatch($filters['viewport'] ?? null);

        if ($filters['use_geo'] && $filters['origin_lat'] !== null && $filters['origin_lng'] !== null) {
            $query = $baseMatch;

            if ($viewportMatch !== null) {
                $query['location'] = $viewportMatch;
            } else {
                $query['location'] = ['$ne' => null];
            }

            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $filters['origin_lng'], (float) $filters['origin_lat']],
                ],
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => $query,
            ];

            if ($filters['max_distance_meters'] !== null) {
                $geoNear['maxDistance'] = (float) $filters['max_distance_meters'];
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        } else {
            $pipeline[] = ['$match' => $baseMatch];

            if ($viewportMatch !== null) {
                $pipeline[] = [
                    '$match' => [
                        'location' => $viewportMatch,
                    ],
                ];
            }
        }

        $this->applySearchFilter($pipeline, $filters['search']);
        $this->applyCategoryFilter($pipeline, $filters['categories']);
        $this->applyTagsFilter($pipeline, $filters['tags']);
        $this->applyTaxonomyFilter($pipeline, $filters['taxonomy']);

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
                    ['name' => ['$regex' => $pattern]],
                    ['subtitle' => ['$regex' => $pattern]],
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
                'category' => ['$in' => $regexes],
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
     * @param array<string, mixed> $filters
     * @return array{distance_meters: int}|array{priority: int, distance_meters: int}|array{time_anchor_at: int, priority: int}
     */
    private function resolveSort(string $sort): array
    {
        return match ($sort) {
            'distance' => ['priority' => -1, 'distance_meters' => 1],
            'time_to_event' => ['time_anchor_at' => 1, 'priority' => -1],
            default => ['priority' => -1, 'updated_at' => -1],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function groupStacks(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $key = $item['exact_key'] ?? $this->buildExactKeyFromItem($item);
            $grouped[$key][] = $item;
        }

        $stacks = [];

        foreach ($grouped as $key => $groupItems) {
            usort($groupItems, function (array $left, array $right): int {
                $priorityDiff = ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0);
                if ($priorityDiff !== 0) {
                    return $priorityDiff;
                }

                $precedence = $this->refTypePrecedence();
                $leftRef = $left['ref_type'] ?? '';
                $rightRef = $right['ref_type'] ?? '';

                $leftWeight = $precedence[$leftRef] ?? 99;
                $rightWeight = $precedence[$rightRef] ?? 99;

                $refDiff = $leftWeight <=> $rightWeight;
                if ($refDiff !== 0) {
                    return $refDiff;
                }

                return strcmp((string) ($left['ref_id'] ?? ''), (string) ($right['ref_id'] ?? ''));
            });

            $top = $groupItems[0] ?? null;
            $center = $top['location'] ?? null;

            $stacks[] = [
                'stack_key' => (string) $key,
                'center' => $center,
                'top_poi' => $top,
                'stack_count' => count($groupItems),
                'items' => $groupItems,
            ];
        }

        return $stacks;
    }

    /**
     * @param mixed $poi
     * @return array<string, mixed>
     */
    private function formatPoi(mixed $poi): array
    {
        $payload = $poi;

        $location = $this->getPayloadValue($payload, 'location');
        $coordinates = $this->getPayloadValue($location, 'coordinates');
        $lat = null;
        $lng = null;

        $lngCandidate = $this->getPayloadIndex($coordinates, 0);
        $latCandidate = $this->getPayloadIndex($coordinates, 1);
        if (is_numeric($lngCandidate) && is_numeric($latCandidate)) {
            $lng = (float) $lngCandidate;
            $lat = (float) $latCandidate;
        }

        $response = [
            'ref_type' => (string) ($this->getPayloadValue($payload, 'ref_type') ?? ''),
            'ref_id' => (string) ($this->getPayloadValue($payload, 'ref_id') ?? ''),
            'name' => $this->getPayloadValue($payload, 'name'),
            'subtitle' => $this->getPayloadValue($payload, 'subtitle'),
            'category' => $this->getPayloadValue($payload, 'category'),
            'tags' => $this->normalizePayloadArray($this->getPayloadValue($payload, 'tags', [])),
            'taxonomy_terms' => $this->normalizePayloadArray($this->getPayloadValue($payload, 'taxonomy_terms', [])),
            'priority' => (int) ($this->getPayloadValue($payload, 'priority') ?? 0),
            'location' => $lat !== null && $lng !== null ? ['lat' => $lat, 'lng' => $lng] : null,
            'time_anchor_at' => $this->formatDate($this->getPayloadValue($payload, 'time_anchor_at')),
            'exact_key' => $this->getPayloadValue($payload, 'exact_key'),
        ];

        if ($this->payloadHasKey($payload, 'distance_meters')) {
            $distance = $this->getPayloadValue($payload, 'distance_meters');
            if (is_numeric($distance)) {
                $response['distance_meters'] = (float) $distance;
            }
        }

        return $response;
    }

    /**
     * @param mixed $poi
     * @return array{ref_type: string, ref_id: string, type: string, updated_at: string}
     */
    private function formatStreamDelta(mixed $poi, Carbon $since): array
    {
        $payload = $poi;

        $deletedAt = $this->normalizeDate($this->getPayloadValue($payload, 'deleted_at'));
        $updatedAt = $this->normalizeDate($this->getPayloadValue($payload, 'updated_at'));
        $createdAt = $this->normalizeDate($this->getPayloadValue($payload, 'created_at'));
        $isActive = (bool) ($this->getPayloadValue($payload, 'is_active') ?? true);

        $type = '';
        $timestamp = $updatedAt;

        if ($deletedAt && $deletedAt->greaterThan($since)) {
            $type = 'poi.deleted';
            $timestamp = $deletedAt;
        } elseif (! $isActive && $updatedAt && $updatedAt->greaterThan($since)) {
            $type = 'poi.deleted';
            $timestamp = $updatedAt;
        } elseif ($createdAt && $createdAt->greaterThan($since)) {
            $type = 'poi.created';
            $timestamp = $createdAt;
        } elseif ($updatedAt && $updatedAt->greaterThan($since)) {
            $type = 'poi.updated';
            $timestamp = $updatedAt;
        }

        return [
            'ref_type' => (string) ($this->getPayloadValue($payload, 'ref_type') ?? ''),
            'ref_id' => (string) ($this->getPayloadValue($payload, 'ref_id') ?? ''),
            'type' => $type,
            'updated_at' => $timestamp?->toISOString() ?? '',
        ];
    }

    /**
     * @param mixed $viewport
     * @return array<string, mixed>|null
     */
    private function buildViewportMatch(mixed $viewport): ?array
    {
        if (! is_array($viewport)) {
            return null;
        }

        $north = $viewport['north'] ?? null;
        $south = $viewport['south'] ?? null;
        $east = $viewport['east'] ?? null;
        $west = $viewport['west'] ?? null;

        if (! is_numeric($north) || ! is_numeric($south) || ! is_numeric($east) || ! is_numeric($west)) {
            return null;
        }

        return [
            '$geoWithin' => [
                '$box' => [
                    [(float) $west, (float) $south],
                    [(float) $east, (float) $north],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, float>|null
     */
    private function resolveViewport(array $queryParams): ?array
    {
        $viewport = Arr::get($queryParams, 'viewport');
        if (is_array($viewport) && isset($viewport['north'], $viewport['south'], $viewport['east'], $viewport['west'])) {
            return $viewport;
        }

        $north = Arr::get($queryParams, 'ne_lat');
        $east = Arr::get($queryParams, 'ne_lng');
        $south = Arr::get($queryParams, 'sw_lat');
        $west = Arr::get($queryParams, 'sw_lng');

        if (! is_numeric($north) || ! is_numeric($east) || ! is_numeric($south) || ! is_numeric($west)) {
            return null;
        }

        return [
            'north' => (float) $north,
            'south' => (float) $south,
            'east' => (float) $east,
            'west' => (float) $west,
        ];
    }

    /**
     * @return array{past_hours: float, future_hours: float}
     */
    private function resolveTimeWindow(): array
    {
        $settings = TenantSettings::current();
        $mapUi = MapUiSettings::fromValue($settings?->getAttribute('map_ui'));

        return $mapUi->poiTimeWindowHours->resolveWithDefaults(
            self::DEFAULT_PAST_WINDOW_HOURS,
            self::DEFAULT_FUTURE_WINDOW_HOURS
        );
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
        $mapUi = MapUiSettings::fromValue($settings?->getAttribute('map_ui'));

        return $mapUi->radius->resolveWithDefaults(
            self::DEFAULT_RADIUS_MIN_KM,
            self::DEFAULT_RADIUS_KM,
            self::DEFAULT_RADIUS_MAX_KM
        );
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
     * @param mixed $value
     */
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

    private function normalizeDate(mixed $value): ?Carbon
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
     * @param mixed $items
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

    private function buildExactKeyFromItem(array $item): string
    {
        $location = $item['location'] ?? null;
        if (! is_array($location)) {
            return '';
        }

        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return '';
        }

        return number_format((float) $lat, 6, '.', '')
            . ','
            . number_format((float) $lng, 6, '.', '');
    }

    /**
     * @return array<string, int>
     */
    private function refTypePrecedence(): array
    {
        return [
            'event' => 1,
            'account_profile' => 2,
            'static' => 3,
        ];
    }

    private function payloadHasKey(mixed $payload, string $key): bool
    {
        if (is_array($payload)) {
            return array_key_exists($key, $payload);
        }

        if ($payload instanceof \ArrayAccess) {
            return $payload->offsetExists($key);
        }

        return is_object($payload) && property_exists($payload, $key);
    }

    private function getPayloadValue(mixed $payload, string $key, mixed $default = null): mixed
    {
        if (is_array($payload)) {
            return array_key_exists($key, $payload) ? $payload[$key] : $default;
        }

        if ($payload instanceof \ArrayAccess) {
            return $payload->offsetExists($key) ? $payload[$key] : $default;
        }

        if (is_object($payload) && property_exists($payload, $key)) {
            return $payload->{$key};
        }

        return $default;
    }

    private function getPayloadIndex(mixed $payload, int $index, mixed $default = null): mixed
    {
        if (is_array($payload)) {
            return array_key_exists($index, $payload) ? $payload[$index] : $default;
        }

        if ($payload instanceof \ArrayAccess) {
            return $payload->offsetExists($index) ? $payload[$index] : $default;
        }

        return $default;
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizePayloadArray(mixed $value): array
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

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function buildCategoryFilters(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $category = (string) ($item['category'] ?? '');
            if ($category === '') {
                continue;
            }
            $key = strtolower($category);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return array_values(array_map(static function (string $key, int $count): array {
            return [
                'key' => $key,
                'label' => $key,
                'count' => $count,
            ];
        }, array_keys($counts), $counts));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function buildTagFilters(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $tags = $item['tags'] ?? [];
            if (! is_array($tags)) {
                continue;
            }
            foreach ($tags as $tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    continue;
                }
                $key = strtolower($tag);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return array_values(array_map(static function (string $key, int $count): array {
            return [
                'key' => $key,
                'label' => $key,
                'count' => $count,
            ];
        }, array_keys($counts), $counts));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{type: string, value: string, label: string, count: int}>
     */
    private function buildTaxonomyFilters(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $terms = $item['taxonomy_terms'] ?? [];
            if (! is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (! is_array($term)) {
                    continue;
                }

                $type = trim((string) ($term['type'] ?? ''));
                $value = trim((string) ($term['value'] ?? ''));

                if ($type === '' || $value === '') {
                    continue;
                }

                $key = strtolower($type) . ':' . strtolower($value);
                $counts[$key] = [
                    'type' => $type,
                    'value' => $value,
                    'count' => ($counts[$key]['count'] ?? 0) + 1,
                ];
            }
        }

        return array_values(array_map(static function (array $entry): array {
            return [
                'type' => $entry['type'],
                'value' => $entry['value'],
                'label' => $entry['value'],
                'count' => $entry['count'],
            ];
        }, array_values($counts)));
    }
}
