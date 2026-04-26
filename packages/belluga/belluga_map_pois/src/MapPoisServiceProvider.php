<?php

declare(strict_types=1);

namespace Belluga\MapPois;

use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Application\MapPoiQueryService;
use Belluga\MapPois\Console\Commands\RebuildMapPoisCommand;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\MapPois\Contracts\MapPoiTaxonomySnapshotResolverContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class MapPoisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MapPoiProjectionService::class);
        $this->app->singleton(MapPoiQueryService::class);

        $this->ensureHostBinding(MapPoiSourceReaderContract::class);
        $this->ensureHostBinding(MapPoiRegistryContract::class);
        $this->ensureHostBinding(MapPoiSettingsContract::class);
        $this->ensureHostBinding(MapPoiTenantContextContract::class);
        $this->ensureHostBinding(MapPoiTaxonomySnapshotResolverContract::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildMapPoisCommand::class,
            ]);
        }
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_map_pois host binding missing for [{$abstract}]");
        });
    }
}
