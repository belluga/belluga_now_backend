<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
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
    public function galleryEnabledTypes(): array
    {
        return $this->remember('gallery_enabled', static fn (): array => TenantProfileType::query()
            ->galleryEnabled()
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
    public function contactChannelsEnabledTypes(): array
    {
        return $this->remember('contact_channels_enabled', static fn (): array => TenantProfileType::query()
            ->contactChannelsEnabled()
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
            ->poiEnabled()
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
    public function queryablePubliclyNavigablePoiEnabledTypes(): array
    {
        return $this->remember('queryable_publicly_navigable_poi_enabled', static fn (): array => TenantProfileType::query()
            ->queryable()
            ->publiclyNavigable()
            ->poiEnabled()
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

    public function hasGalleryEnabled(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->galleryEnabledTypes(), true);
    }

    /**
     * @param  \Closure(): array<int, string>  $resolver
     * @return array<int, string>
     */
    private function remember(string $key, \Closure $resolver): array
    {
        $this->refreshIfStale();
        $scopedKey = $this->tenantScopedCacheKey($key);

        if (array_key_exists($scopedKey, $this->cache)) {
            return $this->cache[$scopedKey];
        }

        $this->cache[$scopedKey] = $resolver();

        return $this->cache[$scopedKey];
    }

    private function refreshIfStale(): void
    {
        if ($this->cacheRevision === self::$revision) {
            return;
        }

        $this->cache = [];
        $this->cacheRevision = self::$revision;
    }

    private function tenantScopedCacheKey(string $key): string
    {
        $tenant = Tenant::current();
        $tenantKey = $tenant?->getKey();
        $scope = is_scalar($tenantKey) && trim((string) $tenantKey) !== ''
            ? 'tenant:'.trim((string) $tenantKey)
            : 'tenant:none';

        return "{$scope}:{$key}";
    }
}
