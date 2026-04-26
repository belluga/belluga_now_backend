<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters;

use Belluga\DiscoveryFilters\Registry\DiscoveryFilterEntityRegistry;
use Belluga\DiscoveryFilters\Services\DiscoveryFilterCatalogService;
use Belluga\DiscoveryFilters\Services\DiscoveryFilterSelectionRepairService;
use Illuminate\Support\ServiceProvider;

class DiscoveryFiltersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DiscoveryFilterEntityRegistry::class);
        $this->app->singleton(DiscoveryFilterCatalogService::class);
        $this->app->singleton(DiscoveryFilterSelectionRepairService::class);
    }
}
