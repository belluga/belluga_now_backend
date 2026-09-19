<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Models\Landlord\Tenant;

final class AccountProfileTypeSetProvider
{
    private static int $revision = 0;

    /** @var array<string, array<int, string>> */
    private array $cache = [];

    private int $cacheRevision = -1;

    public function __construct(
        private readonly AccountProfileCapabilityResolverContract $capabilities,
    ) {}

    public static function bumpRevision(): void
    {
        self::$revision++;
    }

    public static function currentRevision(): int
    {
        return self::$revision;
    }

    /**
     * @return array<int, string>
     */
    public function queryableTypes(): array
    {
        return $this->remember('queryable', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues(['is_queryable' => true]));
    }

    /**
     * @return array<int, string>
     */
    public function publiclyDiscoverableTypes(): array
    {
        return $this->remember('publicly_discoverable', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues([
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
            ]));
    }

    /**
     * @return array<int, string>
     */
    public function publicCatalogTypes(): array
    {
        return $this->remember('public_catalog', fn (): array => $this->publiclyDiscoverableTypes());
    }

    /**
     * @return array<int, string>
     */
    public function publiclyNavigableTypes(): array
    {
        return $this->remember('publicly_navigable', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues(['is_publicly_navigable' => true]));
    }

    /**
     * @return array<int, string>
     */
    public function publicPoiCatalogTypes(): array
    {
        return $this->remember('public_poi_catalog', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues([
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_map_poi_enabled' => true,
            ]));
    }

    /**
     * @return array<int, string>
     */
    public function publicPhysicalHostTypes(): array
    {
        return $this->remember('public_physical_host', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues([
                'is_queryable' => true,
                'is_publicly_discoverable' => true,
                'is_physical_host_enabled' => true,
            ]));
    }

    /**
     * @return array<int, string>
     */
    public function galleryEnabledTypes(): array
    {
        return $this->remember('gallery_enabled', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues(['has_gallery' => true]));
    }

    /**
     * @return array<int, string>
     */
    public function contactChannelsEnabledTypes(): array
    {
        return $this->remember('contact_channels_enabled', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues(['has_contact_channels' => true]));
    }

    /**
     * @return array<int, string>
     */
    public function physicalHostEnabledTypes(): array
    {
        return $this->remember('physical_host_enabled', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues([
                'is_physical_host_enabled' => true,
            ]));
    }

    /**
     * @return array<int, string>
     */
    public function locationEnabledTypes(): array
    {
        return $this->remember('location_enabled', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues([
                'location_policy' => ['optional', 'required'],
            ]));
    }

    /**
     * @return array<int, string>
     */
    public function publiclyNavigablePhysicalHostEnabledTypes(): array
    {
        return $this->remember('publicly_navigable_physical_host_enabled', fn (): array => $this->capabilities
            ->typeIdsWhereAllEffectiveValues([
                'is_publicly_navigable' => true,
                'is_physical_host_enabled' => true,
            ]));
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

    public function isPublicCatalog(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->publicCatalogTypes(), true);
    }

    public function hasGalleryEnabled(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->galleryEnabledTypes(), true);
    }

    public function hasContactChannelsEnabled(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->contactChannelsEnabledTypes(), true);
    }

    public function isLocationEnabled(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->locationEnabledTypes(), true);
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
