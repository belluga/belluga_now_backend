<?php

declare(strict_types=1);

namespace App\Listeners\Invites;

use Belluga\Invites\Application\Lifecycle\InviteLifecycleSideEffectService;
use Belluga\Invites\Domain\Events\CreditedInviteAccepted;

final class HandleCreditedInviteAccepted
{
    public function __construct(
        private readonly InviteLifecycleSideEffectService $sideEffects,
    ) {}

    public function handle(CreditedInviteAccepted $event): void
    {
        $this->sideEffects->handleCreditedInviteAccepted(
            $event->inviteId,
            $event->actorUserId,
            $event->supersededInviteIds,
            $event->shareCode,
        );
    }
}
