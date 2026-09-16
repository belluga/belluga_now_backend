<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;

class MapPoiRegistryAdapter implements MapPoiRegistryContract
{
    public function __construct(
        private readonly AccountProfileRegistryService $accountProfiles,
    ) {}

    public function isAccountProfilePoiEnabled(string $profileType): bool
    {
        return $this->accountProfiles->isPoiEnabled($profileType);
    }

    public function resolveAccountProfilePoiVisual(string $profileType): ?array
    {
        return $this->accountProfiles->resolvePoiVisual($profileType);
    }

}
