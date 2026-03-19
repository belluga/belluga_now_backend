<?php

declare(strict_types=1);

namespace Belluga\Favorites\Contracts;

use Belluga\Favorites\Support\FavoriteRegistryDefinition;

interface FavoriteSnapshotBuilderContract
{
    /**
     * Build snapshot payload for one target.
     * Return null to delete existing snapshot for this target.
     *
     * @return array<string, mixed>|null
     */
    public function build(string $targetId, FavoriteRegistryDefinition $definition): ?array;
}
