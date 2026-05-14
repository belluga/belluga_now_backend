<?php

declare(strict_types=1);

namespace App\Listeners\Push;

use App\Jobs\Push\ReconcilePushTokenTopicsJob;
use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Domain\Events\PushDeviceRegistered;

final class SyncPushTopicsForRegisteredDevice
{
    public function handle(PushDeviceRegistered $event): void
    {
        $tenantSlug = $this->currentTenantSlug();
        if ($tenantSlug === null) {
            return;
        }

        ReconcilePushTokenTopicsJob::dispatch(
            tenantSlug: $tenantSlug,
            userId: $event->userId,
            pushToken: $event->pushToken,
        );
    }

    private function currentTenantSlug(): ?string
    {
        $tenant = Tenant::current();
        $slug = trim((string) ($tenant?->slug ?? ''));

        return $slug === '' ? null : $slug;
    }
}
