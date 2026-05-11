<?php

declare(strict_types=1);

namespace Belluga\Favorites\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class FavoriteRemoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $ownerUserId,
        public string $registryKey,
        public string $targetType,
        public string $targetId,
    ) {}
}
