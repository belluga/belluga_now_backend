<?php

declare(strict_types=1);

namespace Belluga\Events\Capabilities;

use Belluga\Events\Contracts\EventCapabilityHandlerContract;
use Belluga\Events\Support\Validation\InputConstraints;

class MapPoiCapabilityHandler implements EventCapabilityHandlerContract
{
    public function key(): string
    {
        return EventCapabilityKey::MAP_POI;
    }

    public function mergeEventConfig(?array $incomingConfig, array $currentConfig): array
    {
        $enabled = (bool) ($currentConfig['enabled'] ?? true);
        $discoveryScope = $this->normalizeDiscoveryScope($currentConfig['discovery_scope'] ?? null);

        if ($incomingConfig !== null) {
            if (array_key_exists('enabled', $incomingConfig)) {
                $enabled = (bool) $incomingConfig['enabled'];
            }
            if (array_key_exists('discovery_scope', $incomingConfig)) {
                $discoveryScope = $this->normalizeDiscoveryScope($incomingConfig['discovery_scope']);
            }
        }

        return [
            'enabled' => $enabled,
            'discovery_scope' => $discoveryScope,
        ];
    }

    public function normalizeTenantConfig(?array $tenantConfig): array
    {
        return [
            'available' => (bool) ($tenantConfig['available'] ?? true),
        ];
    }

    public function assertScheduleConstraints(array $eventConfig, array $tenantConfig, array $occurrences): void
    {
        // map_poi does not enforce schedule cardinality constraints.
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

        if (in_array($type, ['point'], true)) {
            $point = $this->normalizePoint($scope['point'] ?? null);
            if ($point === null) {
                return null;
            }
            $normalized['point'] = $point;
        }

        if (in_array($type, ['range', 'circle'], true)) {
            $center = $this->normalizePoint($scope['center'] ?? null);
            $radiusMeters = isset($scope['radius_meters']) ? (int) $scope['radius_meters'] : 0;

            if ($center === null || $radiusMeters < 1) {
                return null;
            }

            $normalized['center'] = $center;
            $normalized['radius_meters'] = $radiusMeters;
        }

        if ($type === 'polygon') {
            $polygon = $this->normalizePolygon($scope['polygon'] ?? null);
            if ($polygon === null) {
                return null;
            }
            $normalized['polygon'] = $polygon;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePoint(mixed $point): ?array
    {
        if (! is_array($point)) {
            return null;
        }

        $coordinates = $point['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) !== 2) {
            return null;
        }

        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];
        if ($lng < -180.0 || $lng > 180.0 || $lat < -90.0 || $lat > 90.0) {
            return null;
        }

        return [
            'type' => 'Point',
            'coordinates' => [$lng, $lat],
        ];
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
        if (
            ! is_array($coordinates) ||
            $coordinates === [] ||
            count($coordinates) > InputConstraints::MAP_POI_POLYGON_RINGS_MAX
        ) {
            return null;
        }

        $normalizedRings = [];
        foreach ($coordinates as $ring) {
            if (
                ! is_array($ring) ||
                count($ring) < 4 ||
                count($ring) > InputConstraints::MAP_POI_POLYGON_POINTS_PER_RING_MAX
            ) {
                return null;
            }

            $normalizedRing = [];
            foreach ($ring as $point) {
                if (! is_array($point) || count($point) !== 2) {
                    return null;
                }

                $lng = (float) $point[0];
                $lat = (float) $point[1];
                if ($lng < -180.0 || $lng > 180.0 || $lat < -90.0 || $lat > 90.0) {
                    return null;
                }

                $normalizedRing[] = [$lng, $lat];
            }

            $normalizedRings[] = $normalizedRing;
        }

        return [
            'type' => 'Polygon',
            'coordinates' => $normalizedRings,
        ];
    }
}
