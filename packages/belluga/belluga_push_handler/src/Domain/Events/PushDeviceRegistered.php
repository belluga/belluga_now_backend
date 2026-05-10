<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PushDeviceRegistered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $userId,
        public string $pushToken,
    ) {}
}
