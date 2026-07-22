<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfileNestedPublicMembersOutboxConsumer implements AccountProfileOutboxConsumer
{
    private const CONSUMER_ID = 'nested_public_members';

    public function __construct(
        private readonly AccountProfileProjectionCheckpointStore $checkpoints,
        private readonly AccountProfileNestedPublicMembersProjectionService $projection,
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
        $this->checkpoints->advance($context, $this->consumerId(), $event);
    }
}
