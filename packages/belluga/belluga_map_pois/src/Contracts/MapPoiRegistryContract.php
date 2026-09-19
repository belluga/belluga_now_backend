<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiRegistryContract
{
    public function isAccountProfileMapPoiEnabled(string $profileType): bool;

    public function isValidAccountProfilePoint(mixed $location): bool;

    /**
     * @return array<string, string>|null
     */
    public function resolveAccountProfilePoiVisual(string $profileType): ?array;
}
