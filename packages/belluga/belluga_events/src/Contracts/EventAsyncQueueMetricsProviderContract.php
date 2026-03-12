<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventAsyncQueueMetricsProviderContract
{
    /**
     * @return array<int, int> Pending job ages (seconds)
     */
    public function pendingAgesInSeconds(): array;
}
