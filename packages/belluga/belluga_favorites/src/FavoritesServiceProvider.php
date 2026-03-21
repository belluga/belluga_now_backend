<?php

declare(strict_types=1);

namespace Belluga\Favorites;

use Belluga\Favorites\Application\Favorites\FavoritesCommandService;
use Belluga\Favorites\Application\Favorites\FavoriteSnapshotProjectionService;
use Belluga\Favorites\Application\Favorites\FavoritesQueryService;
use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Belluga\Favorites\Support\InMemoryFavoritesRegistry;
use Illuminate\Support\ServiceProvider;

class FavoritesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FavoritesRegistryContract::class, InMemoryFavoritesRegistry::class);
        $this->app->singleton(FavoriteSnapshotProjectionService::class);
        $this->app->singleton(FavoritesCommandService::class);
        $this->app->singleton(FavoritesQueryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
