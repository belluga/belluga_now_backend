<?php

declare(strict_types=1);

namespace App\Listeners\Push;

use App\Jobs\Push\UnsubscribePushTokensFromAllTopicsJob;
use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Domain\Events\PushDeviceUnregistered;
use Belluga\PushHandler\Domain\Events\PushTokensInvalidated;

final class UnsubscribeInvalidatedPushTokens
{
    public function handle(PushTokensInvalidated|PushDeviceUnregistered $event): void
    {
        $tenantSlug = $this->currentTenantSlug();
        if ($tenantSlug === null) {
            return;
        }

        UnsubscribePushTokensFromAllTopicsJob::dispatch(
            tenantSlug: $tenantSlug,
            tokens: $event->tokens,
        );
    }

    private function currentTenantSlug(): ?string
    {
        $tenant = Tenant::current();
        $slug = trim((string) ($tenant?->slug ?? ''));

        return $slug === '' ? null : $slug;
    }
}
