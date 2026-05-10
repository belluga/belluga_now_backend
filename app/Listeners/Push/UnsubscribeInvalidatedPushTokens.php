<?php

declare(strict_types=1);

namespace App\Listeners\Push;

use App\Application\Push\PushTopicMembershipService;
use Belluga\PushHandler\Domain\Events\PushDeviceUnregistered;
use Belluga\PushHandler\Domain\Events\PushTokensInvalidated;
use Illuminate\Contracts\Queue\ShouldQueue;

final class UnsubscribeInvalidatedPushTokens implements ShouldQueue
{
    public function __construct(
        private readonly PushTopicMembershipService $memberships,
    ) {}

    public function handle(PushTokensInvalidated|PushDeviceUnregistered $event): void
    {
        $this->memberships->unsubscribeTokensFromAll($event->tokens);
    }
}
