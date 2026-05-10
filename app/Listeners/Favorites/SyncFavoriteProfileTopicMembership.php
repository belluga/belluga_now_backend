<?php

declare(strict_types=1);

namespace App\Listeners\Favorites;

use App\Application\Push\PushTopicMembershipService;
use Belluga\Favorites\Domain\Events\FavoriteAdded;
use Belluga\Favorites\Domain\Events\FavoriteRemoved;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SyncFavoriteProfileTopicMembership implements ShouldQueue
{
    public function __construct(
        private readonly PushTopicMembershipService $memberships,
    ) {}

    public function handle(FavoriteAdded|FavoriteRemoved $event): void
    {
        if ($event->targetType !== 'account_profile') {
            return;
        }

        if ($event instanceof FavoriteAdded) {
            $this->memberships->subscribeUserToFavoriteProfile($event->ownerUserId, $event->targetId);

            return;
        }

        $this->memberships->unsubscribeUserFromFavoriteProfile($event->ownerUserId, $event->targetId);
    }
}
