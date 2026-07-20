<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Social\InviteablePeopleProjectionService;

final class AccountProfileInviteablePeopleOutboxConsumer implements AccountProfileOutboxConsumer
{
    private const CONSUMER_ID = 'inviteable_people';

    public function __construct(
        private readonly AccountProfileProjectionCheckpointStore $checkpoints,
        private readonly InviteablePeopleProjectionService $projection,
    ) {}

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    /** @param array<string, mixed> $event */
    public function consume(AccountProfileTransactionContext $context, array $event): void
    {
        if ($this->checkpoints->isAtOrAhead($context, $this->consumerId(), $event)) {
            return;
        }

        $this->projection->refreshImpactedByAccountProfileOutboxEvent($context, $event);

        // Invite projections and the monotonic tuple commit together.
        $this->checkpoints->advance($context, $this->consumerId(), $event);
    }
}
