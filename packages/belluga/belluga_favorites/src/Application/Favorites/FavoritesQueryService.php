<?php

declare(strict_types=1);

namespace Belluga\Favorites\Application\Favorites;

use Belluga\Favorites\Contracts\AccountProfileFavoriteDirectReadContract;
use Belluga\Favorites\Contracts\FavoritesRegistryContract;

class FavoritesQueryService
{
    private const DEFAULT_PAGE_SIZE = 10;

    public function __construct(
        private readonly FavoritesRegistryContract $registry,
        private readonly AccountProfileFavoriteDirectReadContract $accountProfileDirectReadService,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function listForOwner(
        string $ownerUserId,
        int $page,
        int $pageSize,
        ?string $registryKey = null,
        ?string $targetType = null,
    ): array {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;

        $effectiveRegistryKey = $registryKey;
        if (! is_string($effectiveRegistryKey) || trim($effectiveRegistryKey) === '') {
            $effectiveRegistryKey = (string) config('favorites.default_registry_key', 'account_profile');
        }

        $definition = $this->registry->find($effectiveRegistryKey);
        if (! $definition) {
            return [
                'items' => [],
                'has_more' => false,
            ];
        }

        $effectiveTargetType = $targetType;
        if (! is_string($effectiveTargetType) || trim($effectiveTargetType) === '') {
            $effectiveTargetType = $definition->targetType;
        }

        if ($definition->registryKey === 'account_profile' && $effectiveTargetType === 'account_profile') {
            return $this->accountProfileDirectReadService->listForOwner(
                ownerUserId: $ownerUserId,
                page: $resolvedPage,
                pageSize: $resolvedPageSize,
            );
        }

        return [
            'items' => [],
            'has_more' => false,
        ];
    }
}
