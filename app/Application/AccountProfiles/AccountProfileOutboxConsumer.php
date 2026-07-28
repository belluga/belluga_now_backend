<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

interface AccountProfileOutboxConsumer
{
    public function consumerId(): string;

    /** @param array<string, mixed> $event */
    public function consume(AccountProfileTransactionContext $context, array $event): void;
}
