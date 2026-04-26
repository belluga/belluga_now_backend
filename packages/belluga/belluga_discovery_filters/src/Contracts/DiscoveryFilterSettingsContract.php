<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters\Contracts;

interface DiscoveryFilterSettingsContract
{
    /**
     * @return array<string, mixed>
     */
    public function resolveDiscoveryFiltersSettings(): array;
}
