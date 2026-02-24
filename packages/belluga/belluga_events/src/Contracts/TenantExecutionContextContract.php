<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface TenantExecutionContextContract
{
    /**
     * @param callable(): void $callback
     */
    public function runForEachTenant(callable $callback): void;
}
