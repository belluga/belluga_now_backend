<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Favorites\AccountProfileFavoriteDirectReadService;
use Belluga\Favorites\Contracts\AccountProfileFavoriteDirectReadContract;
use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Belluga\Favorites\Support\FavoriteRegistryDefinition;
use Illuminate\Support\ServiceProvider;

class FavoritesIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AccountProfileFavoriteDirectReadContract::class,
            AccountProfileFavoriteDirectReadService::class
        );
    }

    public function boot(): void
    {
        /** @var FavoritesRegistryContract $registry */
        $registry = $this->app->make(FavoritesRegistryContract::class);

        $registries = config('favorites.registries', []);
        if (! is_array($registries)) {
            $registries = [];
        }

        foreach ($registries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $registryKey = isset($entry['registry_key']) ? trim((string) $entry['registry_key']) : '';
            $targetType = isset($entry['target_type']) ? trim((string) $entry['target_type']) : '';

            if ($registryKey === '' || $targetType === '') {
                continue;
            }

            $registry->register(new FavoriteRegistryDefinition(
                registryKey: $registryKey,
                targetType: $targetType,
            ));
        }
    }
}
