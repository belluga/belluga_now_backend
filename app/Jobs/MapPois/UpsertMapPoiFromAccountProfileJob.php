<?php

declare(strict_types=1);

namespace App\Jobs\MapPois;

use App\Application\MapPois\MapPoiProjectionService;
use App\Models\Tenants\AccountProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpsertMapPoiFromAccountProfileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $profileId)
    {
    }

    public function handle(MapPoiProjectionService $projectionService): void
    {
        $profile = AccountProfile::query()->find($this->profileId);

        if (! $profile) {
            $projectionService->deleteByRef('account_profile', $this->profileId);
            return;
        }

        $projectionService->upsertFromAccountProfile($profile);
    }
}
