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

    /**
     * @param  array{primary?: mixed, taxonomy?: mixed}  $selection
     * @param  array<string, mixed>  $catalog
     * @return array{primary: array<int, string>, taxonomy: array<string, array<int, string>>, changed: bool}
     */
    public function repairSelectionAgainstCanonicalCatalog(
        array $selection,
        array $catalog,
    ): array;
}
