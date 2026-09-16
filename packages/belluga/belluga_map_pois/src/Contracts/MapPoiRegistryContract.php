<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiRegistryContract
{
    public function isAccountProfilePoiEnabled(string $profileType): bool;

    /**
     * @return array<string, string>|null
     */
    public function resolveAccountProfilePoiVisual(string $profileType): ?array;

}
