<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\RuntimeDiscoveryFilterCatalogService;
use Belluga\Events\Contracts\EventDiscoveryFilterCatalogContract;

class EventDiscoveryFilterCatalogAdapter implements EventDiscoveryFilterCatalogContract
{
    public function __construct(
        private readonly RuntimeDiscoveryFilterCatalogService $runtimeDiscoveryFilterCatalogService,
    ) {}

    public function buildCanonicalCatalog(
        string $surface,
        ?array $runtimeFacets,
        ?string $baseUrl = null,
    ): array {
        return $this->runtimeDiscoveryFilterCatalogService->buildCanonicalCatalog(
            $surface,
            $runtimeFacets,
            $baseUrl,
        );
    }
}
