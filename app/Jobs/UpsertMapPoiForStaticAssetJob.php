<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Map\MapPoiProjectionService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpsertMapPoiForStaticAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenant_id,
        public readonly string $static_asset_id
    ) {
    }

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $previousTenant = Tenant::current();
        $tenant = Tenant::query()->where('_id', $this->tenant_id)->first();
        if (! $tenant) {
            return;
        }

        $tenant->makeCurrent();

        $asset = StaticAsset::query()->where('_id', $this->static_asset_id)->first();
        if ($asset) {
            $projectionService->upsertForStaticAsset($asset);
        } else {
            $projectionService->removeByReference('static', $this->static_asset_id);
        }

        if ($previousTenant) {
            $previousTenant->makeCurrent();
        } else {
            $tenant->forgetCurrent();
        }
    }
}
