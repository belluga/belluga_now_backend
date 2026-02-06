<?php

declare(strict_types=1);

namespace App\Jobs\MapPois;

use App\Application\MapPois\MapPoiProjectionService;
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

    public function __construct(
        private readonly string $refType,
        private readonly string $refId
    ) {
    }

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $projectionService->deleteByRef($this->refType, $this->refId);
    }
}
