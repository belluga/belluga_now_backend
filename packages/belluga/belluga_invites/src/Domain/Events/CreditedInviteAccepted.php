<?php

declare(strict_types=1);

namespace Belluga\Invites\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class CreditedInviteAccepted implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<int, string>  $supersededInviteIds
     */
    public function __construct(
        public string $inviteId,
        public string $actorUserId,
        public array $supersededInviteIds = [],
        public ?string $shareCode = null,
    ) {}
}
