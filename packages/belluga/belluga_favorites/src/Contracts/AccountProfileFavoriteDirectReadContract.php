<?php

declare(strict_types=1);

namespace Belluga\Favorites\Contracts;

interface AccountProfileFavoriteDirectReadContract
{
    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function listForOwner(
        string $ownerUserId,
        int $page,
        int $pageSize,
    ): array;
}
