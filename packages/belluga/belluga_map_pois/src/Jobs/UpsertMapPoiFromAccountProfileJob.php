<?php

declare(strict_types=1);

namespace Belluga\MapPois\Jobs;

use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class UpsertMapPoiFromAccountProfileJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $profileId,
        private readonly ?int $forcedCheckpoint = null,
    ) {}

    public function handle(
        MapPoiProjectionService $projectionService,
        MapPoiSourceReaderContract $sourceReader,
    ): void {
        $profile = $sourceReader->findAccountProfileById($this->profileId);

        if (! $profile) {
            $projectionService->deleteByRef('account_profile', $this->profileId);

            return;
        }

        $projectionService->upsertFromAccountProfile($profile, $this->forcedCheckpoint);
    }
}
