<?php

declare(strict_types=1);

namespace App\Listeners\Invites;

use Belluga\Invites\Application\Lifecycle\InviteLifecycleSideEffectService;
use Belluga\Invites\Domain\Events\DirectInviteCreated;

final class HandleDirectInviteCreated
{
    public function __construct(
        private readonly InviteLifecycleSideEffectService $sideEffects,
    ) {}

    public function handle(DirectInviteCreated $event): void
    {
        $this->sideEffects->handleDirectInviteCreated($event->inviteId, $event->actorUserId);
    }
}
