<?php

declare(strict_types=1);

namespace Belluga\MapPois\Application;

use Belluga\MapPois\Application\Concerns\MapPoiQueryFormatting;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

class MapPoiQueryService
{
    use MapPoiQueryFormatting;

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
     * @param  array<string, mixed>  $queryParams
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
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    public function filters(array $queryParams, ?string $timezone): array
    {
        $basePipeline = $this->buildBasePipeline($queryParams, $timezone, false);
        $configuredCategories = $this->configuredCategoryMetadata();

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

        $categoryCountByKey = [];
        foreach ($categories as $row) {
            $rowData = $this->normalizeDocument($row);
            $rowId = $rowData['_id'] ?? $rowData['id'] ?? null;
            if ($rowId === null || $rowId === '') {
                continue;
            }
            $key = strtolower(trim((string) $rowId));
            if ($key === '') {
                continue;
            }
            $categoryCountByKey[$key] = (int) ($rowData['count'] ?? 0);
        }
        $categoryItems = $this->buildConfiguredCategoryItems(
            $queryParams,
            $timezone,
            $configuredCategories,
            $categoryCountByKey
        );

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
     * @param  array<string, mixed>  $queryParams
     * @param array<string, array{
     *   key: string,
     *   position: int,
     *   label: string,
     *   image_uri: ?string,
     *   query: array{
     *     source: ?string,
     *     types: array<int, string>,
     *     taxonomy: array<int, string>,
     *     tags: array<int, string>,
     *     categories: array<int, string>
     *   }
     * }> $metadataByKey
     * @param  array<string, int>  $categoryCountByKey
     * @return array<int, array<string, mixed>>
     */
    private function buildConfiguredCategoryItems(
        array $queryParams,
        ?string $timezone,
        array $metadataByKey,
        array $categoryCountByKey
    ): array {
        if ($metadataByKey === []) {
            return [];
        }

        $orderedMetadata = array_values($metadataByKey);
        usort(
            $orderedMetadata,
            static fn (array $left, array $right): int => ((int) $left['position']) <=> ((int) $right['position'])
        );

        $items = [];
        foreach ($orderedMetadata as $metadata) {
            $key = strtolower(trim((string) ($metadata['key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $query = is_array($metadata['query'] ?? null)
                ? $metadata['query']
                : [
                    'source' => null,
                    'types' => [],
                    'taxonomy' => [],
                    'tags' => [],
                    'categories' => [],
                ];

            $hasScopedQuery = $query['source'] !== null ||
                ($query['types'] ?? []) !== [] ||
                ($query['taxonomy'] ?? []) !== [] ||
                ($query['tags'] ?? []) !== [] ||
                ($query['categories'] ?? []) !== [];

            $count = $hasScopedQuery
                ? $this->countConfiguredCategoryMatches(
                    $queryParams,
                    $timezone,
                    $key,
                    $query
                )
                : (int) ($categoryCountByKey[$key] ?? 0);

            $item = [
                'key' => $key,
                'label' => (string) ($metadata['label'] ?? $key),
                'count' => $count,
            ];

            $imageUri = $metadata['image_uri'] ?? null;
            if (is_string($imageUri) && trim($imageUri) !== '') {
                $item['image_uri'] = trim($imageUri);
            }

            if ($hasScopedQuery) {
                $item['query'] = [
                    ...($query['source'] !== null ? ['source' => $query['source']] : []),
                    ...(($query['types'] ?? []) !== [] ? ['types' => array_values($query['types'])] : []),
                    ...(($query['taxonomy'] ?? []) !== [] ? ['taxonomy' => array_values($query['taxonomy'])] : []),
                    ...(($query['tags'] ?? []) !== [] ? ['tags' => array_values($query['tags'])] : []),
                    ...(($query['categories'] ?? []) !== [] ? ['categories' => array_values($query['categories'])] : []),
                ];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param array{
     *   source: ?string,
     *   types: array<int, string>,
     *   taxonomy: array<int, string>,
     *   tags: array<int, string>,
     *   categories: array<int, string>
     * } $query
     */
    private function countConfiguredCategoryMatches(
        array $queryParams,
        ?string $timezone,
        string $fallbackCategoryKey,
        array $query
    ): int {
        $pipeline = $this->buildBasePipeline($queryParams, $timezone, false);
        $pipeline = $this->appendFilterConstraintToPipeline(
            $pipeline,
            $fallbackCategoryKey,
            $query
        );
        $pipeline[] = ['$count' => 'total'];

        $rows = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        foreach ($rows as $row) {
            $data = $this->normalizeDocument($row);

            return (int) ($data['total'] ?? 0);
        }

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @param array{
     *   source: ?string,
     *   types: array<int, string>,
     *   taxonomy: array<int, string>,
     *   tags: array<int, string>,
     *   categories: array<int, string>
     * } $query
     * @return array<int, array<string, mixed>>
     */
    private function appendFilterConstraintToPipeline(
        array $pipeline,
        string $fallbackCategoryKey,
        array $query
    ): array {
        if ($pipeline === []) {
            return $pipeline;
        }

        $constraint = $this->applyFilterQueryToMatch(
            $fallbackCategoryKey,
            $query
        );

        if ($constraint === []) {
            return $pipeline;
        }

        $first = $pipeline[0];
        if (isset($first['$match']) && is_array($first['$match'])) {
            $pipeline[0]['$match'] = array_merge($first['$match'], $constraint);

            return $pipeline;
        }

        if (
            isset($first['$geoNear']) &&
            is_array($first['$geoNear']) &&
            is_array($first['$geoNear']['query'] ?? null)
        ) {
            $pipeline[0]['$geoNear']['query'] = array_merge(
                $first['$geoNear']['query'],
                $constraint
            );
        }

        return $pipeline;
    }

    /**
     * @param array{
     *   source: ?string,
     *   types: array<int, string>,
     *   taxonomy: array<int, string>,
     *   tags: array<int, string>,
     *   categories: array<int, string>
     * } $query
     * @return array<string, mixed>
     */
    private function applyFilterQueryToMatch(
        string $fallbackCategoryKey,
        array $query
    ): array {
        $match = [];

        $source = $query['source'] ?? null;
        if (is_string($source) && trim($source) !== '') {
            $refType = $this->mapSourceToRefType($source);
            if ($refType !== null) {
                $match['ref_type'] = $refType;
            }
        }

        $types = $this->normalizeStringArray($query['types'] ?? []);
        if ($types !== []) {
            $match['source_type'] = ['$in' => $types];
        }

        $categories = $this->normalizeStringArray($query['categories'] ?? []);
        if ($categories !== []) {
            $match['category'] = ['$in' => $categories];
        }

        $taxonomy = $this->normalizeStringArray($query['taxonomy'] ?? []);
        if ($taxonomy !== []) {
            $match['taxonomy_terms_flat'] = ['$in' => $taxonomy];
        }

        $tags = $this->normalizeStringArray($query['tags'] ?? []);
        if ($tags !== []) {
            $match['tags'] = ['$in' => $tags];
        }

        if ($categories === [] && $source === null && $types === [] && $taxonomy === [] && $tags === []) {
            $match['category'] = $fallbackCategoryKey;
        }

        return $match;
    }

    /**
     * @return array<string, array{
     *   key: string,
     *   position: int,
     *   label: string,
     *   image_uri: ?string,
     *   query: array{
     *     source: ?string,
     *     types: array<int, string>,
     *     taxonomy: array<int, string>,
     *     tags: array<int, string>,
     *     categories: array<int, string>
     *   }
     * }>
     */
    private function configuredCategoryMetadata(): array
    {
        $mapUiSettings = $this->settings->resolveMapUiSettings();
        $rawFilters = $mapUiSettings['filters'] ?? null;
        if (! is_array($rawFilters)) {
            return [];
        }

        $metadata = [];
        $position = 0;

        foreach ($rawFilters as $rawFilter) {
            $filter = $this->normalizeDocument($rawFilter);
            $rawKey = $filter['key'] ?? null;
            if (! is_string($rawKey)) {
                continue;
            }

            $key = strtolower(trim($rawKey));
            if ($key === '' || isset($metadata[$key])) {
                continue;
            }

            $label = $filter['label'] ?? null;
            if (! is_string($label) || trim($label) === '') {
                $label = $key;
            } else {
                $label = trim($label);
            }

            $imageUri = $filter['image_uri'] ?? null;
            if (! is_string($imageUri) || trim($imageUri) === '') {
                $imageUri = null;
            } else {
                $imageUri = trim($imageUri);
            }

            $query = $this->normalizeConfiguredFilterQuery(
                is_array($filter['query'] ?? null) ? $filter['query'] : []
            );

            $metadata[$key] = [
                'key' => $key,
                'position' => $position,
                'label' => $label,
                'image_uri' => $imageUri,
                'query' => $query,
            ];
            $position++;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{
     *   source: ?string,
     *   types: array<int, string>,
     *   taxonomy: array<int, string>,
     *   tags: array<int, string>,
     *   categories: array<int, string>
     * }
     */
    private function normalizeConfiguredFilterQuery(array $query): array
    {
        $sourceRaw = strtolower(trim((string) ($query['source'] ?? '')));
        $source = $sourceRaw === '' ? null : $sourceRaw;

        return [
            'source' => $source,
            'types' => $this->normalizeStringArray($query['types'] ?? []),
            'taxonomy' => $this->normalizeStringArray($query['taxonomy'] ?? []),
            'tags' => $this->normalizeStringArray($query['tags'] ?? []),
            'categories' => $this->normalizeStringArray(
                $query['categories'] ?? ($query['category_keys'] ?? [])
            ),
        ];
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
