<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;

final class TenantRequestLifecycleMongoCommandSubscriber implements CommandSubscriber
{
    public function __construct(
        private readonly string $connectionName,
        private readonly TenantRequestLifecycleTrace $trace,
    ) {}

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->trace->recordFirstMongoCommand($this->connectionName, $event);
    }

    public function commandSucceeded(CommandSucceededEvent $event): void {}

    public function commandFailed(CommandFailedEvent $event): void {}
}
