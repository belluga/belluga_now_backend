<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiTaxonomySnapshotResolverContract
{
    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return array<int, array{type: string, value: string, name: string, taxonomy_name: string, label: string}>
     */
    public function resolve(array $terms): array;
}
