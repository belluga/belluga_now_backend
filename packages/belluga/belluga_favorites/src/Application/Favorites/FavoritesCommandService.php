<?php

declare(strict_types=1);

namespace Belluga\Favorites\Application\Favorites;

use Belluga\Favorites\Contracts\FavoritesRegistryContract;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Illuminate\Support\Carbon;

class FavoritesCommandService
{
    public function __construct(
        private readonly FavoritesRegistryContract $registry,
    ) {}

    /**
     * @return array{registry_key:string,target_type:string,target_id:string}|null
     */
    public function favorite(
        string $ownerUserId,
        string $targetId,
        ?string $registryKey = null,
        ?string $targetType = null,
    ): ?array {
        $selector = $this->resolveSelector(
            targetId: $targetId,
            registryKey: $registryKey,
            targetType: $targetType,
        );

        if ($selector === null) {
            return null;
        }

        FavoriteEdge::query()->updateOrCreate(
            [
                'owner_user_id' => $ownerUserId,
                'registry_key' => $selector['registry_key'],
                'target_type' => $selector['target_type'],
                'target_id' => $selector['target_id'],
            ],
            [
                'favorited_at' => Carbon::now(),
            ],
        );

        return $selector;
    }

    /**
     * @return array{registry_key:string,target_type:string,target_id:string}|null
     */
    public function unfavorite(
        string $ownerUserId,
        string $targetId,
        ?string $registryKey = null,
        ?string $targetType = null,
    ): ?array {
        $selector = $this->resolveSelector(
            targetId: $targetId,
            registryKey: $registryKey,
            targetType: $targetType,
        );

        if ($selector === null) {
            return null;
        }

        FavoriteEdge::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('registry_key', $selector['registry_key'])
            ->where('target_type', $selector['target_type'])
            ->where('target_id', $selector['target_id'])
            ->delete();

        return $selector;
    }

    /**
     * @return array{registry_key:string,target_type:string,target_id:string}|null
     */
    private function resolveSelector(
        string $targetId,
        ?string $registryKey,
        ?string $targetType,
    ): ?array {
        $resolvedTargetId = trim($targetId);
        if ($resolvedTargetId === '') {
            return null;
        }

        $effectiveRegistryKey = is_string($registryKey) && trim($registryKey) !== ''
            ? trim($registryKey)
            : (string) config('favorites.default_registry_key', 'account_profile');

        $definition = $this->registry->find($effectiveRegistryKey);
        if (! $definition) {
            return null;
        }

        $effectiveTargetType = is_string($targetType) && trim($targetType) !== ''
            ? trim($targetType)
            : $definition->targetType;

        if ($effectiveTargetType !== $definition->targetType) {
            return null;
        }

        return [
            'registry_key' => $definition->registryKey,
            'target_type' => $effectiveTargetType,
            'target_id' => $resolvedTargetId,
        ];
    }
}

