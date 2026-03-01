<?php

declare(strict_types=1);

namespace App\Application\MapPois;

use App\Models\Tenants\MapPoi;
use App\Models\Tenants\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use MongoDB\BSON\UTCDateTime;

class MapPoiQueryService
{
    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function stacks(array $queryParams, ?string $timezone): array
    {
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
                'stacks' => $stack ? [$stack] : [],
            ];
        }

        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true);
        $pipeline[] = [
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
        $pipeline[] = [
            '$sort' => [
                'priority' => -1,
                'ref_type_order' => 1,
                'ref_id' => 1,
            ],
        ];
        $pipeline[] = [
            '$group' => [
                '_id' => '$exact_key',
                'stack_count' => ['$sum' => 1],
                'top_poi' => ['$first' => '$$ROOT'],
                'center' => ['$first' => '$location'],
            ],
        ];
        $pipeline[] = [
            '$project' => [
                '_id' => 0,
                'stack_key' => '$_id',
                'stack_count' => 1,
                'top_poi' => 1,
                'center' => 1,
            ],
        ];

        $rawStacks = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        $stacks = [];
        foreach ($rawStacks as $stack) {
            $stacks[] = $this->formatStackFromAggregate($stack);
        }

        return [
            'tenant_id' => $this->resolveTenantId(),
            'server_time' => $serverTime,
            'bounds' => $bounds,
            'stacks' => $stacks,
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function near(array $queryParams, ?string $timezone): array
    {
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $pageSize = (int) ($queryParams['page_size'] ?? 10);
        if ($pageSize <= 0) {
            $pageSize = 10;
        }
        if ($pageSize > 50) {
            $pageSize = 50;
        }

        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true, true);
        $pipeline[] = [
            '$sort' => [
                'distance_meters' => 1,
                'priority' => -1,
                'ref_id' => 1,
            ],
        ];
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
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function filters(array $queryParams, ?string $timezone): array
    {
        $basePipeline = $this->buildBasePipeline($queryParams, $timezone, false);

        $categoryPipeline = array_merge($basePipeline, [
            ['$group' => ['_id' => '$category', 'count' => ['$sum' => 1]]],
            ['$sort' => ['count' => -1, '_id' => 1]],
        ]);

        $tagPipeline = array_merge($basePipeline, [
            ['$unwind' => '$tags'],
            ['$group' => ['_id' => '$tags', 'count' => ['$sum' => 1]]],
            ['$sort' => ['count' => -1, '_id' => 1]],
        ]);

        $taxonomyPipeline = array_merge($basePipeline, [
            ['$unwind' => '$taxonomy_terms'],
            ['$group' => [
                '_id' => [
                    'type' => '$taxonomy_terms.type',
                    'value' => '$taxonomy_terms.value',
                ],
                'count' => ['$sum' => 1],
            ]],
            ['$sort' => ['count' => -1, '_id.type' => 1, '_id.value' => 1]],
        ]);

        $categories = MapPoi::raw(function ($collection) use ($categoryPipeline) {
            return $collection->aggregate($categoryPipeline);
        });
        $tags = MapPoi::raw(function ($collection) use ($tagPipeline) {
            return $collection->aggregate($tagPipeline);
        });
        $taxonomies = MapPoi::raw(function ($collection) use ($taxonomyPipeline) {
            return $collection->aggregate($taxonomyPipeline);
        });

        $categoryItems = [];
        foreach ($categories as $row) {
            $rowData = $this->normalizeDocument($row);
            $rowId = $rowData['_id'] ?? $rowData['id'] ?? null;
            if ($rowId === null || $rowId === '') {
                continue;
            }
            $categoryItems[] = [
                'key' => (string) $rowId,
                'label' => (string) $rowId,
                'count' => (int) ($rowData['count'] ?? 0),
            ];
        }

        $tagItems = [];
        foreach ($tags as $row) {
            $rowData = $this->normalizeDocument($row);
            $rowId = $rowData['_id'] ?? $rowData['id'] ?? null;
            if ($rowId === null || $rowId === '') {
                continue;
            }
            $tagItems[] = [
                'key' => (string) $rowId,
                'label' => (string) $rowId,
                'count' => (int) ($rowData['count'] ?? 0),
            ];
        }

        $taxonomyItems = [];
        foreach ($taxonomies as $row) {
            $rowData = $this->normalizeDocument($row);
            $id = $rowData['_id'] ?? $rowData['id'] ?? null;
            $type = null;
            $value = null;

            if (is_array($id)) {
                $type = $id['type'] ?? null;
                $value = $id['value'] ?? null;
            } elseif (is_object($id)) {
                $idData = $this->normalizeDocument($id);
                $type = $idData['type'] ?? null;
                $value = $idData['value'] ?? null;
            }
            if (! $type || ! $value) {
                continue;
            }
            $taxonomyItems[] = [
                'type' => (string) $type,
                'value' => (string) $value,
                'label' => (string) $value,
                'count' => (int) ($rowData['count'] ?? 0),
            ];
        }

        return [
            'tenant_id' => $this->resolveTenantId(),
            'categories' => $categoryItems,
            'tags' => $tagItems,
            'taxonomy_terms' => $taxonomyItems,
        ];
    }

    /**
     * @param array<string, mixed> $queryParams
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
     * @param array<string, mixed> $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function resolveStackItems(array $queryParams, ?string $timezone, string $stackKey): array
    {
        $queryParams['stack_key'] = $stackKey;
        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true);
        $pipeline[] = [
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
        $pipeline[] = [
            '$sort' => [
                'priority' => -1,
                'ref_type_order' => 1,
                'ref_id' => 1,
            ],
        ];

        $items = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        $formatted = [];
        foreach ($items as $item) {
            $formatted[] = $this->formatTopPoi($item);
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $queryParams
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
        } else {
            $pipeline[] = ['$match' => array_merge($match, $geoMatch)];
        }

        return $pipeline;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function buildMatchConditions(array $queryParams, ?string $timezone): array
    {
        $match = [
            'is_active' => true,
        ];

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

    /**
     * @param array<string, mixed> $queryParams
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
        $settings = TenantSettings::current();
        $mapUi = $settings?->getAttribute('map_ui') ?? [];
        $mapUi = is_array($mapUi) ? $mapUi : [];
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
        } catch (\Throwable) {
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

    /**
     * @param mixed $stack
     * @return array<string, mixed>
     */
    private function formatStackFromAggregate(mixed $stack): array
    {
        $payloadData = $this->normalizeDocument($stack);
        $center = $this->formatLocation($payloadData['center'] ?? null);
        $topPoi = $this->formatTopPoi($payloadData['top_poi'] ?? null);

        return [
            'stack_key' => (string) ($payloadData['stack_key'] ?? $payloadData['_id'] ?? ''),
            'center' => $center,
            'stack_count' => (int) ($payloadData['stack_count'] ?? 0),
            'top_poi' => $topPoi,
        ];
    }

    /**
     * @param string $stackKey
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function formatStack(string $stackKey, array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $top = $items[0];

        return [
            'stack_key' => $stackKey,
            'center' => $top['location'] ?? null,
            'stack_count' => count($items),
            'top_poi' => $top,
            'items' => $items,
        ];
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>
     */
    private function formatTopPoi(mixed $item): array
    {
        $payloadData = $this->normalizeDocument($item);
        $location = $this->formatLocation($payloadData['location'] ?? null);
        $distance = isset($payloadData['distance_meters']) ? (float) $payloadData['distance_meters'] : null;

        $payload = [
            'ref_type' => (string) ($payloadData['ref_type'] ?? ''),
            'ref_id' => (string) ($payloadData['ref_id'] ?? ''),
            'category' => (string) ($payloadData['category'] ?? ''),
            'location' => $location,
            'is_happening_now' => (bool) ($payloadData['is_happening_now'] ?? false),
            'priority' => (int) ($payloadData['priority'] ?? 0),
            'updated_at' => $this->formatDate($payloadData['updated_at'] ?? null),
        ];

        if ($distance !== null) {
            $payload['distance_meters'] = $distance;
        }

        return $payload;
    }

    /**
     * @param mixed $item
     * @return array<string, mixed>
     */
    private function formatNearItem(mixed $item): array
    {
        $payloadData = $this->normalizeDocument($item);
        $location = $this->formatLocation($payloadData['location'] ?? null);
        $distance = isset($payloadData['distance_meters']) ? (float) $payloadData['distance_meters'] : null;

        return [
            'ref_type' => (string) ($payloadData['ref_type'] ?? ''),
            'ref_id' => (string) ($payloadData['ref_id'] ?? ''),
            'ref_slug' => (string) ($payloadData['ref_slug'] ?? ''),
            'ref_path' => (string) ($payloadData['ref_path'] ?? ''),
            'title' => (string) ($payloadData['name'] ?? ''),
            'subtitle' => $payloadData['subtitle'] ?? null,
            'category' => (string) ($payloadData['category'] ?? ''),
            'location' => $location,
            'distance_meters' => $distance,
            'is_happening_now' => (bool) ($payloadData['is_happening_now'] ?? false),
            'updated_at' => $this->formatDate($payloadData['updated_at'] ?? null),
            'time_start' => $this->formatDate($payloadData['time_start'] ?? null),
            'time_end' => $this->formatDate($payloadData['time_end'] ?? null),
            'avatar_url' => $payloadData['avatar_url'] ?? null,
            'cover_url' => $payloadData['cover_url'] ?? null,
            'badge' => $payloadData['badge'] ?? null,
            'tags' => $this->normalizeStringArray($payloadData['tags'] ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($payloadData['taxonomy_terms'] ?? []),
            'occurrence_facets' => $this->formatOccurrenceFacets($payloadData['occurrence_facets'] ?? []),
        ];
    }

    /**
     * @param array<int, mixed> $facets
     * @return array<int, array<string, mixed>>
     */
    private function formatOccurrenceFacets(array $facets): array
    {
        $normalized = [];

        foreach ($facets as $facet) {
            if (! is_array($facet)) {
                continue;
            }

            $normalized[] = [
                'occurrence_id' => (string) ($facet['occurrence_id'] ?? ''),
                'occurrence_slug' => isset($facet['occurrence_slug']) ? (string) $facet['occurrence_slug'] : null,
                'starts_at' => (string) ($facet['starts_at'] ?? ''),
                'ends_at' => isset($facet['ends_at']) ? (string) $facet['ends_at'] : null,
                'effective_end' => isset($facet['effective_end']) ? (string) $facet['effective_end'] : null,
                'is_happening_now' => (bool) ($facet['is_happening_now'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $location
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
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

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDocument(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_object($value)) {
            if (method_exists($value, 'getArrayCopy')) {
                $copy = $value->getArrayCopy();
                if (is_array($copy)) {
                    return $copy;
                }
            }

            return get_object_vars($value);
        }

        return [];
    }

    /**
     * @param mixed $value
     */
    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeStringArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }
            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, mixed> $terms
     * @return array<int, array<string, string>>
     */
    private function normalizeTaxonomyTerms(array $terms): array
    {
        $normalized = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
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

    private function resolveTenantId(): ?string
    {
        $tenant = \App\Models\Landlord\Tenant::current();

        return $tenant ? (string) $tenant->_id : null;
    }
}
