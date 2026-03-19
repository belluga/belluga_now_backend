<?php

declare(strict_types=1);

namespace Belluga\Favorites\Jobs;

use Belluga\Favorites\Application\Favorites\FavoriteSnapshotProjectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class RebuildFavoriteSnapshotJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $registryKey,
        public readonly string $targetId,
    ) {}

    public function handle(FavoriteSnapshotProjectionService $projectionService): void
    {
        $projectionService->rebuild($this->registryKey, $this->targetId);
    }
}
