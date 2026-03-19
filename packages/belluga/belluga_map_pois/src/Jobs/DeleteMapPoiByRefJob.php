<?php

declare(strict_types=1);

namespace Belluga\MapPois\Jobs;

use Belluga\MapPois\Application\MapPoiProjectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteMapPoiByRefJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly string $refType,
        private readonly string $refId
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 20, 40];
    }

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $projectionService->deleteByRef($this->refType, $this->refId);
    }
}
