<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

use Belluga\Events\Support\EventAsyncQueueMetricsSnapshot;

interface EventAsyncQueueMetricsProviderContract
{
    public function snapshot(): EventAsyncQueueMetricsSnapshot;
}
