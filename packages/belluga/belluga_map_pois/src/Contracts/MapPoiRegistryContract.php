<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiRegistryContract
{
    public function isAccountProfilePoiEnabled(string $profileType): bool;

    public function isStaticAssetPoiEnabled(string $profileType): bool;

    public function resolveStaticAssetMapCategory(string $profileType): string;
}
