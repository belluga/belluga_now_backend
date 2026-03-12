<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\StaticAssets\StaticProfileTypeRegistryService;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;

class MapPoiRegistryAdapter implements MapPoiRegistryContract
{
    public function __construct(
        private readonly AccountProfileRegistryService $accountProfiles,
        private readonly StaticProfileTypeRegistryService $staticProfiles,
    ) {}

    public function isAccountProfilePoiEnabled(string $profileType): bool
    {
        return $this->accountProfiles->isPoiEnabled($profileType);
    }

    public function isStaticAssetPoiEnabled(string $profileType): bool
    {
        return $this->staticProfiles->isPoiEnabled($profileType);
    }

    public function resolveStaticAssetMapCategory(string $profileType): string
    {
        return $this->staticProfiles->resolveMapCategory($profileType);
    }
}
