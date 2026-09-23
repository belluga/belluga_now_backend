<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\AccountProfiles\AccountProfileLocationPolicy;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;

class MapPoiRegistryAdapter implements MapPoiRegistryContract
{
    public function __construct(
        private readonly AccountProfileRegistryService $accountProfiles,
        private readonly AccountProfileLocationPolicy $locationPolicy,
    ) {}

    public function isAccountProfileMapPoiEnabled(string $profileType): bool
    {
        return $this->accountProfiles->isMapPoiEnabled($profileType);
    }

    public function isValidAccountProfilePoint(mixed $location): bool
    {
        return $this->locationPolicy->hasValidPoint($location);
    }

    public function resolveAccountProfilePoiVisual(string $profileType): ?array
    {
        return $this->accountProfiles->resolvePoiVisual($profileType);
    }
}
