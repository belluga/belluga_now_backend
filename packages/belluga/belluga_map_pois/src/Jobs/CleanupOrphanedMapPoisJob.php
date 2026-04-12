<?php

declare(strict_types=1);

namespace Belluga\MapPois\Jobs;

use Belluga\MapPois\Application\MapPoiOrphanCleanupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class CleanupOrphanedMapPoisJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<int, string>|null  $refTypes
     */
    public function __construct(
        private readonly ?array $refTypes = null,
        private readonly ?int $deletedSinceMinutes = null,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 20, 40];
    }

    /**
     * @return array<int, string>|null
     */
    public function refTypes(): ?array
    {
        return $this->refTypes;
    }

    public function deletedSinceMinutes(): ?int
    {
        return $this->deletedSinceMinutes;
    }

    public function handle(MapPoiOrphanCleanupService $orphanCleanupService): void
    {
        $orphanCleanupService->cleanup($this->refTypes, $this->deletedSinceMinutes);
    }
}
