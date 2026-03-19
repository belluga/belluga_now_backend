<?php

declare(strict_types=1);

namespace Belluga\Favorites\Support;

use Belluga\Favorites\Contracts\FavoritesRegistryContract;

final class InMemoryFavoritesRegistry implements FavoritesRegistryContract
{
    /**
     * @var array<string, FavoriteRegistryDefinition>
     */
    private array $registries = [];

    public function register(FavoriteRegistryDefinition $definition): void
    {
        $this->registries[$definition->registryKey] = $definition;
    }

    public function find(string $registryKey): ?FavoriteRegistryDefinition
    {
        return $this->registries[$registryKey] ?? null;
    }

    public function all(): array
    {
        return $this->registries;
    }
}
