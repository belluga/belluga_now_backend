<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PushDeviceUnregistered implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function __construct(public array $tokens) {}
}
