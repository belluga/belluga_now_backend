<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;

final class AccountProfileTypeSetProvider
{
    private static int $revision = 0;

    /** @var array<string, array<int, string>> */
    private array $cache = [];

    private int $cacheRevision = -1;

    public static function bumpRevision(): void
    {
        self::$revision++;
    }

    /**
     * @return array<int, string>
     */
    public function queryableTypes(): array
    {
        return $this->remember('queryable', static fn (): array => TenantProfileType::query()
            ->queryable()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array<int, string>
     */
    public function publiclyDiscoverableTypes(): array
    {
        return $this->remember('publicly_discoverable', static fn (): array => TenantProfileType::query()
            ->publiclyDiscoverable()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array<int, string>
     */
    public function publicDiscoverySurfaceTypes(): array
    {
        return $this->remember('public_discovery_surface', static fn (): array => TenantProfileType::query()
            ->publicDiscoverySurface()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array<int, string>
     */
    public function publiclyNavigableTypes(): array
    {
        return $this->remember('publicly_navigable', static fn (): array => TenantProfileType::query()
            ->publiclyNavigable()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array<int, string>
     */
    public function publicPoiCatalogTypes(): array
    {
        return $this->remember('public_poi_catalog', static fn (): array => TenantProfileType::query()
            ->publicPoiCatalog()
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array<int, string>
     */
    public function queryablePoiEnabledTypes(): array
    {
        return $this->remember('queryable_poi_enabled', static fn (): array => TenantProfileType::query()
            ->queryable()
            ->where('capabilities.is_poi_enabled', true)
            ->pluck('type')
            ->map(static fn ($type): string => trim((string) $type))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all());
    }

    public function isQueryable(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->queryableTypes(), true);
    }

    public function isPubliclyNavigable(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->publiclyNavigableTypes(), true);
    }

    /**
     * @param  \Closure(): array<int, string>  $resolver
     * @return array<int, string>
     */
    private function remember(string $key, \Closure $resolver): array
    {
        $this->refreshIfStale();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $this->cache[$key] = $resolver();

        return $this->cache[$key];
    }

    private function refreshIfStale(): void
    {
        if ($this->cacheRevision === self::$revision) {
            return;
        }

        $this->cache = [];
        $this->cacheRevision = self::$revision;
    }
}
