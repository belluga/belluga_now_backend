<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventCapabilityHandlerContract
{
    public function key(): string;

    /**
     * @param  array<string, mixed>|null  $incomingConfig
     * @param  array<string, mixed>  $currentConfig
     * @return array<string, mixed>
     */
    public function mergeEventConfig(?array $incomingConfig, array $currentConfig): array;

    /**
     * @param  array<string, mixed>|null  $tenantConfig
     * @return array<string, mixed>
     */
    public function normalizeTenantConfig(?array $tenantConfig): array;

    /**
     * @param  array<string, mixed>  $eventConfig
     * @param  array<string, mixed>  $tenantConfig
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function assertScheduleConstraints(array $eventConfig, array $tenantConfig, array $occurrences): void;
}
