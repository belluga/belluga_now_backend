<?php

declare(strict_types=1);

namespace App\Listeners\Push;

use App\Application\Push\PushTopicMembershipService;
use Belluga\PushHandler\Domain\Events\PushDeviceRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SyncPushTopicsForRegisteredDevice implements ShouldQueue
{
    public function __construct(
        private readonly PushTopicMembershipService $memberships,
    ) {}

    public function handle(PushDeviceRegistered $event): void
    {
        $this->memberships->syncTokenForUser($event->userId, $event->pushToken);
    }
}
