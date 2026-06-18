<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventDiscoveryFilterCatalogContract
{
    /**
     * @param  array<string, mixed>|null  $runtimeFacets
     * @return array<string, mixed>
     */
    public function buildCanonicalCatalog(
        string $surface,
        ?array $runtimeFacets,
        ?string $baseUrl = null,
    ): array;
}
