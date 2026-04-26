<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Models\Tenants\Event;

class EventOccurrenceReconciliationService
{
    public function __construct(
        private readonly TenantExecutionContextContract $tenantExecutionContext,
        private readonly EventAggregateWriteService $eventAggregateWrites,
    ) {}

    public function reconcileAllTenants(): void
    {
        $this->tenantExecutionContext->runForEachTenant(function (): void {
            $this->reconcileCurrentTenant();
        });
    }

    public function reconcileEvent(Event $event): void
    {
        $this->eventAggregateWrites->repairOccurrences($event);
    }

    public function reconcileCurrentTenant(): void
    {
        Event::withTrashed()
            ->orderBy('_id')
            ->cursor()
            ->each(function (Event $event): void {
                $this->reconcileEvent($event);
            });
    }
}
