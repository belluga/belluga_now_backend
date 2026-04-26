<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use Belluga\Events\Contracts\EventTaxonomySnapshotResolverContract;

class EventTaxonomySnapshotResolverAdapter implements EventTaxonomySnapshotResolverContract
{
    public function __construct(
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    public function resolve(array $terms): array
    {
        return $this->taxonomyTermSummaryResolver->resolve($terms);
    }

    public function ensureSnapshots(array $terms): array
    {
        return $this->taxonomyTermSummaryResolver->ensureSnapshots($terms);
    }
}
