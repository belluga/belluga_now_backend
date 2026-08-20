<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use Belluga\Events\Contracts\EventRequestLifecycleTraceContract;

final class EventRequestLifecycleTraceAdapter implements EventRequestLifecycleTraceContract
{
    public function __construct(
        private readonly TenantRequestLifecycleTrace $trace,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $stage, array $context = []): void
    {
        $this->trace->record($stage, $context);
    }
}
