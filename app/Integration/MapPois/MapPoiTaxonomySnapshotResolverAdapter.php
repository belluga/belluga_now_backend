<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use Belluga\MapPois\Contracts\MapPoiTaxonomySnapshotResolverContract;

final class MapPoiTaxonomySnapshotResolverAdapter implements MapPoiTaxonomySnapshotResolverContract
{
    public function __construct(
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    public function resolve(array $terms): array
    {
        return $this->taxonomyTermSummaryResolver->resolve($terms);
    }
}
