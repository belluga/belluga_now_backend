<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventRequestLifecycleTraceContract
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $stage, array $context = []): void;
}
