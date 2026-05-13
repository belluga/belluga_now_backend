<?php

declare(strict_types=1);

namespace App\Listeners\Favorites;

use App\Jobs\Push\SyncFavoriteAccountProfileTopicMembershipJob;
use App\Models\Landlord\Tenant;
use Belluga\Favorites\Domain\Events\FavoriteAdded;
use Belluga\Favorites\Domain\Events\FavoriteRemoved;

final class SyncFavoriteProfileTopicMembership
{
    public function handle(FavoriteAdded|FavoriteRemoved $event): void
    {
        if ($event->targetType !== 'account_profile') {
            return;
        }

        $tenantSlug = $this->currentTenantSlug();
        if ($tenantSlug === null) {
            return;
        }

        SyncFavoriteAccountProfileTopicMembershipJob::dispatch(
            tenantSlug: $tenantSlug,
            userId: $event->ownerUserId,
            accountProfileId: $event->targetId,
        );
    }

    private function currentTenantSlug(): ?string
    {
        $tenant = Tenant::current();
        $slug = trim((string) ($tenant?->slug ?? ''));

        return $slug === '' ? null : $slug;
    }
}
