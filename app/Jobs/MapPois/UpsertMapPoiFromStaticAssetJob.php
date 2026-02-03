<?php

declare(strict_types=1);

namespace App\Jobs\MapPois;

use App\Application\MapPois\MapPoiProjectionService;
use App\Models\Tenants\StaticAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpsertMapPoiFromStaticAssetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $assetId)
    {
    }

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $asset = StaticAsset::query()->find($this->assetId);

        if (! $asset) {
            $projectionService->deleteByRef('static', $this->assetId);
            return;
        }

        $projectionService->upsertFromStaticAsset($asset);
    }
}
