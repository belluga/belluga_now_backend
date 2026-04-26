<?php

declare(strict_types=1);

namespace Belluga\MapPois\Application;

use Belluga\MapPois\Application\Concerns\MapPoiQueryFormatting;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\MapPois\Contracts\MapPoiTaxonomySnapshotResolverContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

class MapPoiQueryService
{
    use MapPoiQueryFormatting;

    private const EVENT_DOMINANCE_RADIUS_METERS = 50.0;

    public function __construct(
        private readonly MapPoiSettingsContract $settings,
        private readonly MapPoiTenantContextContract $tenantContext,
        private readonly MapPoiTaxonomySnapshotResolverContract $taxonomySnapshotResolver,
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

        $stacks = $this->resolveDominantStacks($queryParams, $timezone);

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
                $payload['stack_count'] = $this->resolveStackCount(
                    $stackKey,
                    $timezone
                );
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
                'name' => ['$first' => '$taxonomy_terms.name'],
                'taxonomy_name' => ['$first' => '$taxonomy_terms.taxonomy_name'],
                'label' => ['$first' => '$taxonomy_terms.label'],
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
            $name = $this->normalizeOptionalString($rowData['name'] ?? null)
                ?? $this->normalizeOptionalString($rowData['label'] ?? null)
                ?? (string) $value;
            $taxonomyName = $this->normalizeOptionalString($rowData['taxonomy_name'] ?? null)
                ?? (string) $type;
            $taxonomyItems[] = [
                'type' => (string) $type,
                'value' => (string) $value,
                'name' => $name,
                'taxonomy_name' => $taxonomyName,
                'label' => $name,
                'count' => (int) ($rowData['count'] ?? 0),
            ];
        }
        $taxonomyItems = $this->resolveTaxonomyItemSnapshots($taxonomyItems);

        return [
            'tenant_id' => $this->resolveTenantId(),
            'categories' => $categoryItems,
            'tags' => $tagItems,
            'taxonomy_terms' => $taxonomyItems,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveTaxonomyItemSnapshots(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $resolvedByToken = [];
        foreach ($this->taxonomySnapshotResolver->resolve($items) as $resolved) {
            $type = strtolower(trim((string) ($resolved['type'] ?? '')));
            $value = strtolower(trim((string) ($resolved['value'] ?? '')));
            if ($type === '' || $value === '') {
                continue;
            }
            $resolvedByToken["{$type}:{$value}"] = $resolved;
        }

        foreach ($items as $index => $item) {
            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $value = strtolower(trim((string) ($item['value'] ?? '')));
            $resolved = $resolvedByToken["{$type}:{$value}"] ?? null;
            if ($resolved === null) {
                continue;
            }

            $name = $this->normalizeOptionalString($resolved['name'] ?? null)
                ?? $this->normalizeOptionalString($resolved['label'] ?? null)
                ?? (string) ($item['name'] ?? $value);
            $taxonomyName = $this->normalizeOptionalString($resolved['taxonomy_name'] ?? null)
                ?? (string) ($item['taxonomy_name'] ?? $type);
            $items[$index]['name'] = $name;
            $items[$index]['taxonomy_name'] = $taxonomyName;
            $items[$index]['label'] = $this->normalizeOptionalString($resolved['label'] ?? null) ?? $name;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param array<string, array{
     *   key: string,
     *   position: int,
     *   label: string,
     *   image_uri: ?string,
     *   override_marker: bool,
     *   marker_override: array<string, string>|null,
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
                'override_marker' => (bool) ($metadata['override_marker'] ?? false),
            ];

            $imageUri = $metadata['image_uri'] ?? null;
            if (is_string($imageUri) && trim($imageUri) !== '') {
                $item['image_uri'] = trim($imageUri);
            }
            $markerOverride = $metadata['marker_override'] ?? null;
            if (is_array($markerOverride) && $markerOverride !== []) {
                $item['marker_override'] = $markerOverride;
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
     *   override_marker: bool,
     *   marker_override: array<string, string>|null,
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

            $rawQuery = $this->normalizeDocument($filter['query'] ?? null);
            $query = $this->normalizeConfiguredFilterQuery($rawQuery);
            $rawMarkerOverride = $this->normalizeDocument($filter['marker_override'] ?? null);
            [$overrideMarker, $markerOverride] = $this->normalizeConfiguredMarkerOverride(
                $rawMarkerOverride === [] ? null : $rawMarkerOverride,
                (bool) ($filter['override_marker'] ?? false),
            );

            $metadata[$key] = [
                'key' => $key,
                'position' => $position,
                'label' => $label,
                'image_uri' => $imageUri,
                'override_marker' => $overrideMarker,
                'marker_override' => $markerOverride,
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
     * @param  array<string, mixed>|null  $markerOverride
     * @return array{0: bool, 1: array<string, string>|null}
     */
    private function normalizeConfiguredMarkerOverride(
        ?array $markerOverride,
        bool $overrideMarker,
    ): array {
        if (! $overrideMarker) {
            return [false, null];
        }

        if (! is_array($markerOverride)) {
            return [false, null];
        }

        $mode = strtolower(trim((string) ($markerOverride['mode'] ?? '')));
        if ($mode === 'icon') {
            $icon = trim((string) ($markerOverride['icon'] ?? ''));
            $color = strtoupper(trim((string) ($markerOverride['color'] ?? '')));
            $iconColor = strtoupper(trim((string) ($markerOverride['icon_color'] ?? '#FFFFFF')));
            if (
                $icon === ''
                || preg_match('/^#[0-9A-F]{6}$/', $color) !== 1
                || preg_match('/^#[0-9A-F]{6}$/', $iconColor) !== 1
            ) {
                return [false, null];
            }

            return [
                true,
                [
                    'mode' => 'icon',
                    'icon' => $icon,
                    'color' => $color,
                    'icon_color' => $iconColor,
                ],
            ];
        }

        if ($mode === 'image') {
            $imageUri = trim((string) ($markerOverride['image_uri'] ?? ''));
            if ($imageUri === '') {
                return [false, null];
            }

            return [
                true,
                [
                    'mode' => 'image',
                    'image_uri' => $imageUri,
                ],
            ];
        }

        return [false, null];
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

    private function resolveStackCount(string $stackKey, ?string $timezone): int
    {
        $items = $this->loadStackDocuments([], $timezone, $stackKey);
        $resolvedCount = count($this->applyIntraStackEventDominance($items));

        return $resolvedCount > 0 ? $resolvedCount : 1;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<int, array<string, mixed>>
     */
    private function resolveDominantStacks(array $queryParams, ?string $timezone): array
    {
        $pipeline = $this->buildBasePipeline($queryParams, $timezone, true);
        $pipeline[] = $this->buildRefTypeOrderStage();
        $pipeline[] = $this->buildStackSortStage();
        $pipeline[] = [
            '$group' => [
                '_id' => '$exact_key',
                'stack_count' => ['$sum' => 1],
                'event_count' => [
                    '$sum' => [
                        '$cond' => [
                            ['$eq' => ['$ref_type', 'event']],
                            1,
                            0,
                        ],
                    ],
                ],
                'top_poi' => ['$first' => '$$ROOT'],
                'center' => ['$first' => '$location'],
            ],
        ];
        $pipeline[] = [
            '$project' => [
                '_id' => 0,
                'stack_key' => '$_id',
                'stack_count' => 1,
                'event_count' => 1,
                'top_poi' => 1,
                'center' => 1,
            ],
        ];

        $rows = MapPoi::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        $candidates = [];
        $eventCenters = [];

        foreach ($rows as $row) {
            $data = $this->normalizeDocument($row);
            $stackCount = (int) ($data['stack_count'] ?? 0);
            if ($stackCount <= 0) {
                continue;
            }

            $eventCount = (int) ($data['event_count'] ?? 0);
            $displayCount = $eventCount > 0 ? $eventCount : $stackCount;
            if ($displayCount <= 0) {
                continue;
            }

            $center = $data['center'] ?? null;
            $topPoi = $this->normalizeDocument($data['top_poi'] ?? null);

            $candidate = [
                'stack_key' => trim((string) ($data['stack_key'] ?? '')),
                'center' => $center,
                'stack_count' => $displayCount,
                'top_poi' => $topPoi,
                'has_event' => $eventCount > 0,
            ];

            if ($candidate['has_event']) {
                $coordinates = $this->extractCoordinates($center) ?? $this->extractCoordinates($topPoi['location'] ?? null);
                if ($coordinates !== null) {
                    $eventCenters[] = $coordinates;
                }
            }

            $candidates[] = $candidate;
        }

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

        return array_values($stacks);
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
