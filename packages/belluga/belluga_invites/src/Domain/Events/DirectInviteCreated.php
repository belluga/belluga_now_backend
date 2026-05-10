<?php

declare(strict_types=1);

namespace Belluga\Invites\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class DirectInviteCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $inviteId,
        public string $actorUserId,
    ) {}
}
