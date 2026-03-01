<?php

declare(strict_types=1);

namespace App\Application\MapPois;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\StaticAssets\StaticProfileTypeRegistryService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MapPoiProjectionService
{
    public function __construct(
        private readonly AccountProfileRegistryService $accountProfileRegistryService,
        private readonly StaticProfileTypeRegistryService $staticProfileTypeRegistryService,
    ) {
    }

    public function deleteByRef(string $refType, string $refId): void
    {
        MapPoi::query()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->delete();
    }

    public function upsertFromAccountProfile(AccountProfile $profile): void
    {
        if (! $profile->profile_type) {
            $this->deleteByRef('account_profile', (string) $profile->_id);
            return;
        }

        if (! $this->accountProfileRegistryService->isPoiEnabled($profile->profile_type)) {
            $this->deleteByRef('account_profile', (string) $profile->_id);
            return;
        }

        $location = $this->normalizePoint($profile->location ?? null);
        if (! $location) {
            $this->deleteByRef('account_profile', (string) $profile->_id);
            return;
        }

        $payload = [
            'ref_type' => 'account_profile',
            'ref_id' => (string) $profile->_id,
            'projection_key' => $this->projectionKey('account_profile', (string) $profile->_id),
            'source_checkpoint' => $this->resolveCheckpointFromModel($profile),
            'ref_slug' => $profile->slug ?? null,
            'ref_path' => $this->buildRefPath('account_profile', $profile->slug ?? null),
            'name' => (string) ($profile->display_name ?? ''),
            'subtitle' => $profile->bio ?? null,
            'category' => $profile->profile_type,
            'tags' => [],
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($profile->taxonomy_terms ?? []),
            'taxonomy_terms_flat' => $this->flattenTaxonomyTerms($profile->taxonomy_terms ?? []),
            'location' => $location,
            'discovery_scope' => null,
            'occurrence_facets' => [],
            'is_happening_now' => false,
            'priority' => 40,
            'is_active' => (bool) ($profile->is_active ?? false),
            'active_window_start_at' => null,
            'active_window_end_at' => null,
            'time_start' => null,
            'time_end' => null,
            'avatar_url' => $profile->avatar_url ?? null,
            'cover_url' => $profile->cover_url ?? null,
            'badge' => null,
            'exact_key' => $this->exactKey($location),
        ];

        $this->upsertIdempotent($payload);
    }

    public function upsertFromStaticAsset(StaticAsset $asset): void
    {
        if (! $asset->profile_type) {
            $this->deleteByRef('static', (string) $asset->_id);
            return;
        }

        if (! $this->staticProfileTypeRegistryService->isPoiEnabled($asset->profile_type)) {
            $this->deleteByRef('static', (string) $asset->_id);
            return;
        }

        $location = $this->normalizePoint($asset->location ?? null);
        if (! $location) {
            $this->deleteByRef('static', (string) $asset->_id);
            return;
        }

        $mapCategory = $this->staticProfileTypeRegistryService->resolveMapCategory(
            (string) $asset->profile_type
        );

        $payload = [
            'ref_type' => 'static',
            'ref_id' => (string) $asset->_id,
            'projection_key' => $this->projectionKey('static', (string) $asset->_id),
            'source_checkpoint' => $this->resolveCheckpointFromModel($asset),
            'ref_slug' => $asset->slug ?? null,
            'ref_path' => $this->buildRefPath('static', $asset->slug ?? null),
            'name' => (string) ($asset->display_name ?? ''),
            'subtitle' => $asset->bio ?? null,
            'category' => $mapCategory,
            'tags' => $this->normalizeStringArray($asset->tags ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($asset->taxonomy_terms ?? []),
            'taxonomy_terms_flat' => $this->flattenTaxonomyTerms($asset->taxonomy_terms ?? []),
            'location' => $location,
            'discovery_scope' => null,
            'occurrence_facets' => [],
            'is_happening_now' => false,
            'priority' => 20,
            'is_active' => (bool) ($asset->is_active ?? false),
            'active_window_start_at' => null,
            'active_window_end_at' => null,
            'time_start' => null,
            'time_end' => null,
            'avatar_url' => $asset->avatar_url ?? null,
            'cover_url' => $asset->cover_url ?? null,
            'badge' => null,
            'exact_key' => $this->exactKey($location),
        ];

        $this->upsertIdempotent($payload);
    }

    public function upsertFromEvent(Event $event): void
    {
        $eventId = (string) $event->_id;

        $eventCapability = $this->resolveEventMapPoiCapability($event);
        $occurrenceProjection = $this->resolveOccurrenceProjection($eventId);
        $checkpoint = max(
            $this->toCheckpoint($event->updated_at ?? null),
            (int) ($occurrenceProjection['source_checkpoint'] ?? 0)
        );
        if ($checkpoint <= 0) {
            $checkpoint = (int) Carbon::now()->valueOf();
        }

        if (! ($eventCapability['effective_enabled'] ?? false)) {
            $this->deactivateByRef('event', $eventId, $checkpoint);
            return;
        }

        if (! ($occurrenceProjection['has_active_facets'] ?? false)) {
            $this->deactivateByRef('event', $eventId, $checkpoint);
            return;
        }

        $geometry = $this->resolveEventGeometry($event, $eventCapability['discovery_scope'] ?? null);
        if ($geometry === null) {
            $this->deactivateByRef('event', $eventId, $checkpoint);
            return;
        }

        $categories = $this->normalizeStringArray($this->normalizeArray($event->categories ?? []));
        $venue = $this->normalizeArray($event->venue ?? null);
        $placeRef = $this->normalizeArray($event->place_ref ?? null);
        $placeMetadata = is_array($placeRef['metadata'] ?? null) ? $placeRef['metadata'] : [];
        $thumb = $this->normalizeArray($event->thumb ?? null);
        $thumbData = is_array($thumb['data'] ?? null) ? $thumb['data'] : [];

        $activeWindowStartAt = $occurrenceProjection['active_window_start_at'] ?? null;
        $activeWindowEndAt = $occurrenceProjection['active_window_end_at'] ?? null;
        $isHappeningNow = (bool) ($occurrenceProjection['is_happening_now'] ?? false);

        $payload = [
            'ref_type' => 'event',
            'ref_id' => $eventId,
            'projection_key' => $this->projectionKey('event', $eventId),
            'source_checkpoint' => $checkpoint,
            'ref_slug' => $event->slug ?? null,
            'ref_path' => $this->buildRefPath('event', $event->slug ?? null),
            'name' => (string) ($event->title ?? ''),
            'subtitle' => $placeMetadata['display_name'] ?? ($venue['display_name'] ?? null),
            'category' => $categories[0] ?? 'event',
            'tags' => $this->normalizeStringArray($event->tags ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($event->taxonomy_terms ?? []),
            'taxonomy_terms_flat' => $this->flattenTaxonomyTerms($event->taxonomy_terms ?? []),
            'location' => $geometry['location'],
            'discovery_scope' => $geometry['discovery_scope'],
            'occurrence_facets' => $occurrenceProjection['facets'] ?? [],
            'is_happening_now' => $isHappeningNow,
            'priority' => $this->resolveEventPriority($isHappeningNow),
            'is_active' => true,
            'active_window_start_at' => $activeWindowStartAt,
            'active_window_end_at' => $activeWindowEndAt,
            'time_start' => $activeWindowStartAt,
            'time_end' => $activeWindowEndAt,
            'avatar_url' => null,
            'cover_url' => $thumbData['url'] ?? null,
            'badge' => null,
            'exact_key' => $this->exactKey($geometry['location']),
        ];

        $this->upsertIdempotent($payload);
    }

    private function deactivateByRef(string $refType, string $refId, int $checkpoint): void
    {
        /** @var MapPoi|null $existing */
        $existing = MapPoi::query()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();

        if (! $existing) {
            return;
        }

        $currentCheckpoint = (int) ($existing->source_checkpoint ?? 0);
        if ($currentCheckpoint > $checkpoint) {
            return;
        }

        $existing->fill([
            'projection_key' => $this->projectionKey($refType, $refId),
            'source_checkpoint' => $checkpoint,
            'is_active' => false,
            'occurrence_facets' => [],
            'is_happening_now' => false,
            'active_window_start_at' => null,
            'active_window_end_at' => null,
            'time_start' => null,
            'time_end' => null,
        ]);
        $existing->save();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertIdempotent(array $payload): void
    {
        $refType = (string) ($payload['ref_type'] ?? '');
        $refId = (string) ($payload['ref_id'] ?? '');
        $incomingCheckpoint = (int) ($payload['source_checkpoint'] ?? 0);

        /** @var MapPoi|null $existing */
        $existing = MapPoi::query()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();

        if ($existing) {
            $currentCheckpoint = (int) ($existing->source_checkpoint ?? 0);
            if ($currentCheckpoint > $incomingCheckpoint) {
                return;
            }

            $existing->fill($payload);
            $existing->save();

            return;
        }

        MapPoi::query()->create($payload);
    }

    /**
     * @return array{available: bool, event_enabled: bool, effective_enabled: bool, discovery_scope: array<string, mixed>|null}
     */
    private function resolveEventMapPoiCapability(Event $event): array
    {
        $settings = TenantSettings::current();
        $eventsSettings = $this->normalizeArray($settings?->getAttribute('events') ?? []);
        $tenantCapabilities = $this->normalizeArray($eventsSettings['capabilities'] ?? []);
        $tenantMapPoi = $this->normalizeArray($tenantCapabilities['map_poi'] ?? []);
        $tenantAvailable = (bool) ($tenantMapPoi['available'] ?? true);

        $eventCapabilities = $this->normalizeArray($event->capabilities ?? []);
        $eventMapPoi = $this->normalizeArray($eventCapabilities['map_poi'] ?? []);
        $eventEnabled = (bool) ($eventMapPoi['enabled'] ?? true);
        $discoveryScope = $this->normalizeDiscoveryScope($eventMapPoi['discovery_scope'] ?? null);

        return [
            'available' => $tenantAvailable,
            'event_enabled' => $eventEnabled,
            'effective_enabled' => $tenantAvailable && $eventEnabled,
            'discovery_scope' => $discoveryScope,
        ];
    }

    /**
     * @return array{
     *  facets: array<int, array<string, mixed>>,
     *  has_active_facets: bool,
     *  is_happening_now: bool,
     *  active_window_start_at: Carbon|null,
     *  active_window_end_at: Carbon|null,
     *  source_checkpoint: int
     * }
     */
    private function resolveOccurrenceProjection(string $eventId): array
    {
        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('is_event_published', true)
            ->orderBy('starts_at')
            ->get();

        $defaultDuration = $this->resolveDefaultEventDurationHours();
        $now = Carbon::now();

        $facets = [];
        $hasNow = false;
        $windowStart = null;
        $windowEnd = null;
        $checkpoint = 0;

        foreach ($occurrences as $occurrence) {
            $startsAt = $this->toCarbon($occurrence->starts_at ?? null);
            if (! $startsAt) {
                continue;
            }

            $endsAt = $this->toCarbon($occurrence->ends_at ?? null);
            $effectiveEnd = $endsAt?->copy() ?? $startsAt->copy()->addHours($defaultDuration);
            if ($effectiveEnd->lessThanOrEqualTo($now)) {
                $checkpoint = max($checkpoint, $this->toCheckpoint($occurrence->updated_at ?? null));
                continue;
            }

            $isHappeningNow = $now->greaterThanOrEqualTo($startsAt) && $now->lessThan($effectiveEnd);

            $hasNow = $hasNow || $isHappeningNow;
            $windowStart = $windowStart === null || $startsAt->lessThan($windowStart) ? $startsAt->copy() : $windowStart;
            $windowEnd = $windowEnd === null || $effectiveEnd->greaterThan($windowEnd) ? $effectiveEnd->copy() : $windowEnd;
            $checkpoint = max($checkpoint, $this->toCheckpoint($occurrence->updated_at ?? null));

            $facets[] = [
                'occurrence_id' => isset($occurrence->_id) ? (string) $occurrence->_id : '',
                'occurrence_slug' => isset($occurrence->occurrence_slug) ? (string) $occurrence->occurrence_slug : null,
                'starts_at' => $startsAt->toJSON(),
                'ends_at' => $endsAt?->toJSON(),
                'effective_end' => $effectiveEnd->toJSON(),
                'is_happening_now' => $isHappeningNow,
            ];
        }

        return [
            'facets' => $facets,
            'has_active_facets' => $facets !== [],
            'is_happening_now' => $hasNow,
            'active_window_start_at' => $windowStart,
            'active_window_end_at' => $windowEnd,
            'source_checkpoint' => $checkpoint,
        ];
    }

    /**
     * @param array<string, mixed>|null $discoveryScope
     * @return array{location: array<string, mixed>, discovery_scope: array<string, mixed>|null}|null
     */
    private function resolveEventGeometry(Event $event, ?array $discoveryScope): ?array
    {
        $locationPayload = $this->normalizeArray($event->location ?? []);

        $pointFromLocation = $this->normalizePoint($locationPayload['geo'] ?? $event->geo_location ?? null);
        $scope = $this->normalizeDiscoveryScope($discoveryScope);

        if ($pointFromLocation !== null) {
            return [
                'location' => $pointFromLocation,
                'discovery_scope' => $scope,
            ];
        }

        if ($scope === null) {
            return null;
        }

        $scopeType = (string) ($scope['type'] ?? '');
        if ($scopeType === 'point') {
            $point = $this->normalizePoint($scope['point'] ?? null);
            if ($point === null) {
                return null;
            }

            return [
                'location' => $point,
                'discovery_scope' => $scope,
            ];
        }

        if (in_array($scopeType, ['range', 'circle'], true)) {
            $center = $this->normalizePoint($scope['center'] ?? null);
            if ($center === null) {
                return null;
            }

            return [
                'location' => $center,
                'discovery_scope' => $scope,
            ];
        }

        if ($scopeType === 'polygon') {
            $polygon = $scope['polygon'] ?? null;
            $centroid = $this->resolvePolygonCentroid($polygon);
            if ($centroid === null) {
                return null;
            }

            return [
                'location' => $centroid,
                'discovery_scope' => $scope,
            ];
        }

        return null;
    }

    private function resolveEventPriority(bool $isHappeningNow): int
    {
        return $isHappeningNow ? 80 : 60;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePoint(mixed $point): ?array
    {
        if ($point instanceof \MongoDB\Model\BSONDocument || $point instanceof \MongoDB\Model\BSONArray) {
            $point = $point->getArrayCopy();
        } elseif (is_object($point) && method_exists($point, 'toArray')) {
            $point = $point->toArray();
        }

        if (! is_array($point)) {
            return null;
        }

        $coordinates = $point['coordinates'] ?? null;
        if ($coordinates instanceof \MongoDB\Model\BSONDocument || $coordinates instanceof \MongoDB\Model\BSONArray) {
            $coordinates = $coordinates->getArrayCopy();
        } elseif (is_object($coordinates) && method_exists($coordinates, 'toArray')) {
            $coordinates = $coordinates->toArray();
        }

        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $lng = round((float) $coordinates[0], 5);
        $lat = round((float) $coordinates[1], 5);

        return [
            'type' => 'Point',
            'coordinates' => [$lng, $lat],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeDiscoveryScope(mixed $scope): ?array
    {
        if (! is_array($scope)) {
            return null;
        }

        $type = trim((string) ($scope['type'] ?? ''));
        if (! in_array($type, ['point', 'range', 'circle', 'polygon'], true)) {
            return null;
        }

        $normalized = [
            'type' => $type,
        ];

        if ($type === 'point') {
            $point = $this->normalizePoint($scope['point'] ?? null);
            if ($point === null) {
                return null;
            }
            $normalized['point'] = $point;

            return $normalized;
        }

        if (in_array($type, ['range', 'circle'], true)) {
            $center = $this->normalizePoint($scope['center'] ?? null);
            $radius = isset($scope['radius_meters']) ? (int) $scope['radius_meters'] : 0;
            if ($center === null || $radius < 1) {
                return null;
            }

            $normalized['center'] = $center;
            $normalized['radius_meters'] = $radius;

            return $normalized;
        }

        $polygon = $this->normalizePolygon($scope['polygon'] ?? null);
        if ($polygon === null) {
            return null;
        }
        $normalized['polygon'] = $polygon;

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePolygon(mixed $polygon): ?array
    {
        if (! is_array($polygon)) {
            return null;
        }

        $coordinates = $polygon['coordinates'] ?? null;
        if (! is_array($coordinates) || $coordinates === []) {
            return null;
        }

        return [
            'type' => 'Polygon',
            'coordinates' => $coordinates,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePolygonCentroid(mixed $polygon): ?array
    {
        $normalized = $this->normalizePolygon($polygon);
        if ($normalized === null) {
            return null;
        }

        $rings = $normalized['coordinates'] ?? [];
        if (! is_array($rings) || $rings === [] || ! is_array($rings[0] ?? null)) {
            return null;
        }

        $sumLng = 0.0;
        $sumLat = 0.0;
        $count = 0;
        foreach ($rings[0] as $coordinate) {
            if (! is_array($coordinate) || count($coordinate) < 2) {
                continue;
            }
            $sumLng += (float) $coordinate[0];
            $sumLat += (float) $coordinate[1];
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return $this->normalizePoint([
            'type' => 'Point',
            'coordinates' => [$sumLng / $count, $sumLat / $count],
        ]);
    }

    /**
     * @param array<string, mixed> $location
     */
    private function exactKey(array $location): string
    {
        $coordinates = $location['coordinates'] ?? [0.0, 0.0];
        $lng = number_format((float) ($coordinates[0] ?? 0.0), 5, '.', '');
        $lat = number_format((float) ($coordinates[1] ?? 0.0), 5, '.', '');

        return $lat . ',' . $lng;
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

    /**
     * @param array<int, mixed> $terms
     * @return array<int, string>
     */
    private function flattenTaxonomyTerms(array $terms): array
    {
        $flattened = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            $flattened[] = $type . ':' . $value;
        }

        return array_values(array_unique($flattened));
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

    private function resolveDefaultEventDurationHours(): int
    {
        $settings = TenantSettings::current();
        $events = $settings?->getAttribute('events') ?? [];
        $events = is_array($events) ? $events : [];
        $default = (int) ($events['default_duration_hours'] ?? 3);

        return $default > 0 ? $default : 3;
    }

    private function buildRefPath(string $refType, ?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        return '/' . $refType . '/' . $slug;
    }

    private function projectionKey(string $refType, string $refId): string
    {
        return "{$refType}:{$refId}";
    }

    private function toCheckpoint(mixed $value): int
    {
        $carbon = $this->toCarbon($value);

        return $carbon ? (int) $carbon->valueOf() : 0;
    }

    private function resolveCheckpointFromModel(object $model): int
    {
        $checkpoint = max(
            $this->toCheckpoint($model->updated_at ?? null),
            $this->toCheckpoint($model->created_at ?? null)
        );

        return $checkpoint > 0 ? $checkpoint : (int) Carbon::now()->valueOf();
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
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
     * @return array<int|string, mixed>
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
}
