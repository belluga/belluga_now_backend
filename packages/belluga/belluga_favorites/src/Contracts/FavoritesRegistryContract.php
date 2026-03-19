<?php

declare(strict_types=1);

namespace Belluga\Favorites\Contracts;

use Belluga\Favorites\Support\FavoriteRegistryDefinition;

interface FavoritesRegistryContract
{
    public function register(FavoriteRegistryDefinition $definition): void;

    public function find(string $registryKey): ?FavoriteRegistryDefinition;

    /**
     * @return array<string, FavoriteRegistryDefinition>
     */
    public function all(): array;
}
