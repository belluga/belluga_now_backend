<?php

declare(strict_types=1);

namespace Belluga\Settings\Contracts;

interface TenantEnvironmentSnapshotRepairContract
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function repairCurrentTenant(string $reason, array $context = []): void;
}
