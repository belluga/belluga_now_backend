<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Map\MapPoiProjectionService;
use App\Models\Landlord\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveMapPoiByReferenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenant_id,
        public readonly string $ref_type,
        public readonly string $ref_id
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
        $projectionService->removeByReference($this->ref_type, $this->ref_id);
        if ($previousTenant) {
            $previousTenant->makeCurrent();
        } else {
            $tenant->forgetCurrent();
        }
    }
}
